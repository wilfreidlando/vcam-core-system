<?php

declare(strict_types=1);

namespace Tests\Invariants;

use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Wallet\Contracts\CreditWallet;
use Core\Wallet\Contracts\CreditWalletCommand;
use Core\Wallet\Contracts\DebitWallet;
use Core\Wallet\Contracts\DebitWalletCommand;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fitness function 11 — the four financial invariants, against real
 * PostgreSQL. These were written in Phase 0, before the ledger existed, as
 * `markTestIncomplete` placeholders (docs/gouvernance/definition-of-done.md,
 * "Definition of Done spécifique Money"); Lot 2 fills them in for real.
 *
 * Deliberately does NOT use RefreshDatabase: the fourth invariant needs
 * genuinely committed rows visible to separate OS processes (see
 * `Core\Wallet\Tests\Integration\WalletConcurrencyTest`, which this test
 * reuses the same technique from), and RefreshDatabase's per-test wrapping
 * transaction is never really committed. The append-only ledger tables mean
 * rows from this file accumulate in the testing database across runs — an
 * accepted tradeoff for a disposable database recreated by CI (see
 * WalletConcurrencyTest's own docblock).
 *
 * @see docs/architecture/garde-fous-inactifs.md (G11)
 */
#[Group('invariants')]
final class LedgerInvariantsTest extends TestCase
{
    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-000000000010';

    #[Test]
    public function every_transaction_balances_to_zero(): void
    {
        $accounts = app(AccountResolver::class);
        $poster = app(LedgerPoster::class);

        $wallet = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'wallet', (string) Str::uuid(), 'XAF'));
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'clearing', '', 'XAF'));

        // A legitimate, balanced posting always sums to zero. Wrapped
        // explicitly: LedgerPoster::post() must be called inside the
        // caller's own transaction (its own contract) — Wallet's use cases
        // get this for free from UnitOfWork, but this test, calling it
        // directly with no ambient RefreshDatabase transaction, must supply
        // one itself, or each entry insert becomes its own auto-committed
        // statement and the deferred trigger fires too early.
        $ref = DB::transaction(fn () => $poster->post(new PostLedgerTransactionCommand(
            self::PROJECT_ID,
            'wallet_credit',
            null,
            (string) Str::uuid(),
            [
                new LedgerEntryInput($clearing->id, Direction::Debit, 1_000, 'XAF'),
                new LedgerEntryInput($wallet->id, Direction::Credit, 1_000, 'XAF'),
            ],
        )));

        self::assertSame(0, $this->signedSum('transaction_id', $ref->id));

        // The database's own defence, independent of the application code
        // above: an unbalanced insert is rejected at COMMIT, not silently
        // accepted. With no ambient RefreshDatabase transaction, this is a
        // real commit — the deferred trigger raises a raw PDOException from
        // the commit() call itself, not a QueryException from a statement.
        $transactionId = (string) Str::uuid();
        $threw = null;

        try {
            DB::transaction(function () use ($transactionId, $wallet, $clearing): void {
                DB::table('ledger_transactions')->insert([
                    'id' => $transactionId,
                    'project_id' => self::PROJECT_ID,
                    'type' => 'wallet_credit',
                    'external_ref' => null,
                    'idempotency_key' => (string) Str::uuid(),
                    'created_at' => now(),
                ]);
                DB::table('ledger_entries')->insert([
                    ['transaction_id' => $transactionId, 'account_id' => $clearing->id, 'direction' => 'debit', 'amount_minor' => 1_000, 'currency' => 'XAF', 'created_at' => now()],
                    ['transaction_id' => $transactionId, 'account_id' => $wallet->id, 'direction' => 'credit', 'amount_minor' => 500, 'currency' => 'XAF', 'created_at' => now()],
                ]);
            });
        } catch (QueryException|PDOException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'An unbalanced transaction must be rejected at commit.');
        self::assertStringContainsString('does not balance', $threw->getMessage());
    }

    #[Test]
    public function the_materialised_balance_equals_the_sum_of_its_entries(): void
    {
        $partyId = (string) Str::uuid();

        $credited = app(CreditWallet::class)->credit(new CreditWalletCommand(
            self::PROJECT_ID, $partyId, 10_000, 'XAF', null, (string) Str::uuid(),
        ));
        app(DebitWallet::class)->debit(new DebitWalletCommand($credited->walletId, 2_500, null, (string) Str::uuid()));
        $final = app(DebitWallet::class)->debit(new DebitWalletCommand($credited->walletId, 1_500, null, (string) Str::uuid()));

        $walletAccountId = DB::table('ledger_accounts')
            ->where('project_id', self::PROJECT_ID)
            ->where('kind', 'wallet')
            ->where('reference', $credited->walletId)
            ->value('id');

        self::assertSame($final->availableMinor, $this->signedSum('account_id', $walletAccountId));
        self::assertSame(6_000, $final->availableMinor);
    }

    /**
     * See `Core\Wallet\Tests\Integration\WalletConcurrencyTest` for the full
     * scenario (ten concurrent debits, real OS processes) and why
     * RefreshDatabase cannot be used here. This test asserts the invariant
     * itself — the wallet's own guardrail proves the deterministic count.
     */
    #[Test]
    public function concurrent_debits_never_produce_a_negative_balance(): void
    {
        Artisan::call('migrate', ['--force' => true]);

        $partyId = (string) Str::uuid();
        $credited = app(CreditWallet::class)->credit(new CreditWalletCommand(
            self::PROJECT_ID, $partyId, 500, 'XAF', null, (string) Str::uuid(),
        ));

        $handles = [];
        for ($i = 0; $i < 8; $i++) {
            $handles[] = $this->startDebitProcess($credited->walletId, 150);
        }

        foreach ($handles as $handle) {
            proc_close($handle);
        }

        $finalBalance = DB::table('wallet_balances')->where('wallet_id', $credited->walletId)->value('available_minor');
        self::assertIsNumeric($finalBalance);
        self::assertGreaterThanOrEqual(0, (int) $finalBalance);
    }

    #[Test]
    public function replaying_an_identical_operation_has_no_second_effect(): void
    {
        $accounts = app(AccountResolver::class);
        $poster = app(LedgerPoster::class);

        $wallet = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'wallet', (string) Str::uuid(), 'XAF'));
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'clearing', '', 'XAF'));
        $idempotencyKey = (string) Str::uuid();

        $command = new PostLedgerTransactionCommand(
            self::PROJECT_ID,
            'wallet_credit',
            null,
            $idempotencyKey,
            [
                new LedgerEntryInput($clearing->id, Direction::Debit, 750, 'XAF'),
                new LedgerEntryInput($wallet->id, Direction::Credit, 750, 'XAF'),
            ],
        );

        $first = DB::transaction(fn () => $poster->post($command));
        $second = DB::transaction(fn () => $poster->post($command));

        self::assertSame($first->id, $second->id);
        self::assertTrue($second->wasAlreadyPosted);
        self::assertSame(1, DB::table('ledger_transactions')->where('project_id', self::PROJECT_ID)->where('idempotency_key', $idempotencyKey)->count());
        self::assertCount(2, DB::table('ledger_entries')->where('transaction_id', $first->id)->get());
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('ledger_entries')) {
            self::markTestSkipped(
                'The ledger does not exist yet (Lot 2, G11). '.
                'These invariants are the Definition of Done for the Money lot.'
            );
        }
    }

    private function signedSum(string $groupColumn, mixed $value): int
    {
        $sum = 0;
        foreach (DB::table('ledger_entries')->where($groupColumn, $value)->get() as $entry) {
            self::assertIsNumeric($entry->amount_minor);
            $amountMinor = (int) $entry->amount_minor;
            $sum += $entry->direction === 'credit' ? $amountMinor : -$amountMinor;
        }

        return $sum;
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
