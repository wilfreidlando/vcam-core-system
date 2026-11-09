<?php

declare(strict_types=1);

namespace Core\Wallet\Tests\Integration;

use Core\Parties\Contracts\FindOrCreatePartyCommand;
use Core\Parties\Contracts\PartyFinder;
use Core\Wallet\Contracts\CreditWallet;
use Core\Wallet\Contracts\CreditWalletCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0065 under real concurrency, in the spirit of WalletConcurrencyTest:
 * transfers crossing in opposite directions (A→B and B→A) run in separate
 * OS processes, each with its own PostgreSQL connection. Locking balances
 * in ascending wallet-id order must keep them deadlock-free; the row locks
 * must keep both wallets non-negative; the ledger must still equal the
 * materialised balances. Committed fixture under a fresh project id (no
 * RefreshDatabase — the subprocesses would not see it), the same choice as
 * WalletConcurrencyTest: ledger rows are immutable by design.
 */
#[Group('integration')]
#[Group('concurrency')]
final class TransferConcurrencyTest extends TestCase
{
    private const INITIAL_MINOR = 300;

    private const AMOUNT_MINOR = 100;

    private const ATTEMPTS_PER_DIRECTION = 5;

    /**
     * Wallets created by the test, so the outbox rows they produced can
     * be removed when the test is over.
     *
     * @var list<string>
     */
    private array $walletIds = [];

    #[Test]
    public function crossing_transfers_neither_deadlock_nor_overdraw(): void
    {
        Artisan::call('migrate', ['--force' => true]);

        $projectId = (string) Str::uuid();
        $refA = 'party-a-'.Str::random(6);
        $refB = 'party-b-'.Str::random(6);
        $walletIds = [];
        foreach ([$refA, $refB] as $ref) {
            $party = app(PartyFinder::class)->findOrCreate(new FindOrCreatePartyCommand($projectId, $ref, 'wallet_holder'));
            $walletIds[$ref] = app(CreditWallet::class)->credit(new CreditWalletCommand(
                $projectId, $party->id, self::INITIAL_MINOR, 'XAF', null, (string) Str::uuid(),
            ))->walletId;
        }

        $this->walletIds = array_values($walletIds);

        $handles = [];
        for ($i = 0; $i < self::ATTEMPTS_PER_DIRECTION; $i++) {
            $handles[] = $this->startTransfer($projectId, $refA, $refB);
            $handles[] = $this->startTransfer($projectId, $refB, $refA);
        }

        $successes = 0;
        foreach ($handles as $handle) {
            if (proc_close($handle) === 0) {
                $successes++;
            }
        }

        self::assertGreaterThan(0, $successes, 'At least some transfers must succeed.');

        $total = 0;
        foreach ($walletIds as $walletId) {
            $raw = DB::table('wallet_balances')->where('wallet_id', $walletId)->value('available_minor');
            self::assertIsNumeric($raw);
            $balance = (int) $raw;
            self::assertGreaterThanOrEqual(0, $balance, 'A wallet must never go negative.');
            $total += $balance;

            $ledger = 0;
            foreach (DB::table('ledger_entries')
                ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
                ->where('ledger_accounts.kind', 'wallet')->where('ledger_accounts.reference', $walletId)
                ->get(['ledger_entries.direction', 'ledger_entries.amount_minor']) as $entry) {
                self::assertIsNumeric($entry->amount_minor);
                $ledger += $entry->direction === 'credit' ? (int) $entry->amount_minor : -(int) $entry->amount_minor;
            }
            self::assertSame($ledger, $balance, 'The materialised balance must equal the ledger.');
        }

        // Zero-sum: transfers never create nor destroy value.
        self::assertSame(2 * self::INITIAL_MINOR, $total);
        self::assertSame($successes, DB::table('wallet_operations')->where('project_id', $projectId)->count());
    }

    /**
     * The outbox rows this test leaves behind must not outlive it.
     *
     * The fixture is genuinely committed (the subprocesses need to see
     * it), so its `wallet.credited` / `wallet.debited` events stay in
     * `event_outbox` as real, unpublished rows. The relay test and the
     * webhook test neutralise that backlog in their own `setUp`; the
     * readiness probe does not, and an outbox row older than five
     * minutes makes it report the Core as degraded — a failure that has
     * nothing to do with the code under test.
     *
     * The ledger rows cannot be deleted: the grand livre is append-only
     * by design (ADR-0006). The outbox has no such trigger, and its
     * rows are a queue, not a record of value.
     */
    protected function tearDown(): void
    {
        foreach ($this->walletIds as $walletId) {
            DB::table('event_outbox')
                ->where('payload->wallet_id', $walletId)
                ->delete();
        }

        parent::tearDown();
    }

    /**
     * @return resource
     */
    private function startTransfer(string $projectId, string $from, string $to)
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
            // The safety flags must be **the ones of the parent process**:
            // the subprocess rebuilds its environment by hand and would
            // otherwise inherit the developer's local .env, where the
            // private targets are allowed — the Core would then refuse to
            // start (ADR-0115) and every transfer would fail.
            'OUTBOUND_ALLOW_PRIVATE_TARGETS' => $this->flag('integration.outbound.allow_private_targets'),
            'OUTBOUND_ALLOW_INSECURE_HTTP' => $this->flag('integration.outbound.allow_insecure_http'),
        ];

        $process = proc_open(
            [PHP_BINARY, base_path('artisan'), 'test:transfer-once', $projectId, $from, $to, (string) self::AMOUNT_MINOR],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $env,
        );
        if ($process === false) {
            self::fail('Failed to spawn test:transfer-once.');
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        return $process;
    }

    private function configString(string $key): string
    {
        $value = config($key);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * A boolean flag, as the environment spells it.
     */
    private function flag(string $key): string
    {
        return filter_var(config($key), FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }
}
