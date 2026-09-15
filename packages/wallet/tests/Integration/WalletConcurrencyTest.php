<?php

declare(strict_types=1);

namespace Core\Wallet\Tests\Integration;

use Core\Wallet\Contracts\CreditWallet;
use Core\Wallet\Contracts\CreditWalletCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fitness function 11's hardest case, and the Lot 2 Definition of Done's
 * explicit requirement (plan de delivery §9): "tests de concurrence verts".
 *
 * A single PHP process cannot hold two overlapping database transactions at
 * once, so this test spawns real OS processes — `php artisan wallet:debit`,
 * the same console entrypoint an operator would use — each with its own
 * PostgreSQL connection, all targeting the same wallet at once. The
 * pessimistic row lock (`SELECT ... FOR UPDATE`, ADR-0006) taken in
 * `DebitWalletUseCase::debit()` is what must serialise them.
 *
 * Deliberately does NOT use RefreshDatabase: that trait wraps the test in a
 * transaction on the *test process's own* connection, which never really
 * commits — so a subprocess connecting fresh would never see the wallet
 * this test sets up. The fixture here is genuinely committed, and cleaned
 * up by hand at the end.
 */
#[Group('integration')]
#[Group('concurrency')]
final class WalletConcurrencyTest extends TestCase
{
    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-000000000003';

    private const INITIAL_BALANCE_MINOR = 1_000;

    private const DEBIT_AMOUNT_MINOR = 150;

    private const CONCURRENT_ATTEMPTS = 10;

    #[Test]
    public function ten_concurrent_debits_against_one_wallet_never_overdraw_it(): void
    {
        // Migrations are already applied by earlier tests in the run; this
        // is a no-op then, and a safety net when this file runs alone.
        Artisan::call('migrate', ['--force' => true]);

        $partyId = (string) Str::uuid();
        $walletView = app(CreditWallet::class)->credit(new CreditWalletCommand(
            self::PROJECT_ID,
            $partyId,
            self::INITIAL_BALANCE_MINOR,
            'XAF',
            null,
            (string) Str::uuid(),
        ));
        $walletId = $walletView->walletId;

        $handles = [];
        for ($i = 0; $i < self::CONCURRENT_ATTEMPTS; $i++) {
            $handles[] = $this->startDebitProcess($walletId, self::DEBIT_AMOUNT_MINOR);
        }

        $successes = 0;
        foreach ($handles as $handle) {
            $exitCode = proc_close($handle);
            if ($exitCode === 0) {
                $successes++;
            }
        }

        $expectedSuccesses = intdiv(self::INITIAL_BALANCE_MINOR, self::DEBIT_AMOUNT_MINOR);
        $expectedFinalBalance = self::INITIAL_BALANCE_MINOR - $expectedSuccesses * self::DEBIT_AMOUNT_MINOR;

        self::assertSame(
            $expectedSuccesses,
            $successes,
            "Expected exactly {$expectedSuccesses} of ".self::CONCURRENT_ATTEMPTS.' concurrent debits to succeed — '.
            'row locking should serialise them deterministically, not just avoid a negative balance.'
        );

        $finalBalance = DB::table('wallet_balances')->where('wallet_id', $walletId)->value('available_minor');
        self::assertIsNumeric($finalBalance);
        self::assertSame($expectedFinalBalance, (int) $finalBalance);
        self::assertGreaterThanOrEqual(0, (int) $finalBalance, 'The wallet must never go negative.');

        // The materialised balance must still equal the ledger's own truth.
        $walletAccountId = DB::table('ledger_accounts')
            ->where('project_id', self::PROJECT_ID)
            ->where('kind', 'wallet')
            ->where('reference', $walletId)
            ->value('id');

        $derivedBalance = 0;
        foreach (DB::table('ledger_entries')->where('account_id', $walletAccountId)->get() as $entry) {
            self::assertIsNumeric($entry->amount_minor);
            $amountMinor = (int) $entry->amount_minor;
            $derivedBalance += $entry->direction === 'credit' ? $amountMinor : -$amountMinor;
        }

        self::assertSame($expectedFinalBalance, $derivedBalance);
    }

    /**
     * @return resource
     */
    private function startDebitProcess(string $walletId, int $amountMinor)
    {
        $env = [
            'PATH' => (string) getenv('PATH'),
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $this->configString('database.connections.pgsql.host'),
            'DB_PORT' => $this->configString('database.connections.pgsql.port'),
            'DB_DATABASE' => $this->configString('database.connections.pgsql.database'),
            'DB_USERNAME' => $this->configString('database.connections.pgsql.username'),
            'DB_PASSWORD' => $this->configString('database.connections.pgsql.password'),
            'APP_ENV' => 'testing',
        ];

        $command = [PHP_BINARY, base_path('artisan'), 'wallet:debit', $walletId, (string) $amountMinor];

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);

        if ($process === false) {
            self::fail('Failed to spawn wallet:debit subprocess.');
        }

        // Detach the pipes without reading them synchronously — reading
        // would serialise the very concurrency this test needs. proc_close()
        // waits for exit; the OS pipe buffer is large enough for this
        // command's tiny output.
        fclose($pipes[1]);
        fclose($pipes[2]);

        return $process;
    }

    private function configString(string $key): string
    {
        $value = config($key);

        self::assertIsScalar($value, "Expected config('{$key}') to be scalar.");

        return (string) $value;
    }
}
