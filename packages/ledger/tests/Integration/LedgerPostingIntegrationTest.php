<?php

declare(strict_types=1);

namespace Core\Ledger\Tests\Integration;

use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Proves the two PostgreSQL triggers (2026_09_15_000004_create_ledger_entries_table)
 * hold against a real database, not just against the Domain validation that
 * runs before them (already covered in Unit/Domain/LedgerTransactionTest).
 *
 * The balance trigger is DEFERRABLE INITIALLY DEFERRED: it only evaluates at
 * COMMIT, which RefreshDatabase's per-test wrapping transaction never
 * reaches. `SET CONSTRAINTS ALL IMMEDIATE` forces the pending check to run
 * without needing a real top-level commit — the standard technique for
 * testing deferred constraints inside a transactional test.
 */
final class LedgerPostingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-000000000001';

    #[Test]
    public function it_posts_a_balanced_transaction_and_persists_both_entries(): void
    {
        $accounts = app(AccountResolver::class);
        $poster = app(LedgerPoster::class);

        $wallet = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'wallet', 'wallet-1', 'XAF'));
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'clearing', '', 'XAF'));

        $ref = $poster->post(new PostLedgerTransactionCommand(
            self::PROJECT_ID,
            'wallet_credit',
            null,
            (string) Str::uuid(),
            [
                new LedgerEntryInput($clearing->id, Direction::Debit, 1_000, 'XAF'),
                new LedgerEntryInput($wallet->id, Direction::Credit, 1_000, 'XAF'),
            ],
        ));

        self::assertFalse($ref->wasAlreadyPosted);

        $entries = DB::table('ledger_entries')->where('transaction_id', $ref->id)->get();
        self::assertCount(2, $entries);

        $imbalance = 0;
        foreach ($entries as $entry) {
            self::assertIsNumeric($entry->amount_minor);
            $amountMinor = (int) $entry->amount_minor;
            $imbalance += $entry->direction === 'credit' ? $amountMinor : -$amountMinor;
        }
        self::assertSame(0, $imbalance);
    }

    #[Test]
    public function it_rejects_an_unbalanced_insert_at_the_deferred_constraint(): void
    {
        $accounts = app(AccountResolver::class);
        $wallet = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'wallet', 'wallet-2', 'XAF'));
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'clearing', '', 'XAF'));

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
                    ['transaction_id' => $transactionId, 'account_id' => $wallet->id, 'direction' => 'credit', 'amount_minor' => 999, 'currency' => 'XAF', 'created_at' => now()],
                ]);

                // Forces the deferred trigger to evaluate now, without
                // needing this transaction to really COMMIT.
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'Expected the deferred balance trigger to reject an unbalanced transaction.');
        self::assertStringContainsString('does not balance', $threw->getMessage());

        // The whole insert rolled back with the trigger's exception.
        self::assertSame(0, DB::table('ledger_entries')->where('transaction_id', $transactionId)->count());
    }

    #[Test]
    public function it_rejects_updating_an_entry(): void
    {
        [$entryId] = $this->postOneBalancedEntryPair();

        $threw = null;

        try {
            DB::transaction(function () use ($entryId): void {
                DB::table('ledger_entries')->where('id', $entryId)->update(['amount_minor' => 1]);
            });
        } catch (QueryException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'Expected the immutability trigger to reject an UPDATE.');
        self::assertStringContainsString('append-only', $threw->getMessage());
    }

    #[Test]
    public function it_rejects_deleting_an_entry(): void
    {
        [$entryId] = $this->postOneBalancedEntryPair();

        $threw = null;

        try {
            DB::transaction(function () use ($entryId): void {
                DB::table('ledger_entries')->where('id', $entryId)->delete();
            });
        } catch (QueryException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'Expected the immutability trigger to reject a DELETE.');
        self::assertStringContainsString('append-only', $threw->getMessage());
    }

    #[Test]
    public function replaying_the_same_idempotency_key_posts_no_second_transaction(): void
    {
        $accounts = app(AccountResolver::class);
        $poster = app(LedgerPoster::class);

        $wallet = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'wallet', 'wallet-3', 'XAF'));
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'clearing', '', 'XAF'));
        $idempotencyKey = (string) Str::uuid();

        $command = new PostLedgerTransactionCommand(
            self::PROJECT_ID,
            'wallet_credit',
            null,
            $idempotencyKey,
            [
                new LedgerEntryInput($clearing->id, Direction::Debit, 500, 'XAF'),
                new LedgerEntryInput($wallet->id, Direction::Credit, 500, 'XAF'),
            ],
        );

        $first = $poster->post($command);
        $second = $poster->post($command);

        self::assertSame($first->id, $second->id);
        self::assertTrue($second->wasAlreadyPosted);
        self::assertSame(1, DB::table('ledger_transactions')->where('project_id', self::PROJECT_ID)->where('idempotency_key', $idempotencyKey)->count());
        self::assertCount(2, DB::table('ledger_entries')->where('transaction_id', $first->id)->get());
    }

    /**
     * @return array{0: int} the id of one of the two inserted entries
     */
    private function postOneBalancedEntryPair(): array
    {
        $accounts = app(AccountResolver::class);
        $poster = app(LedgerPoster::class);

        $wallet = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'wallet', 'wallet-immutable', 'XAF'));
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'clearing', '', 'XAF'));

        $ref = $poster->post(new PostLedgerTransactionCommand(
            self::PROJECT_ID,
            'wallet_credit',
            null,
            (string) Str::uuid(),
            [
                new LedgerEntryInput($clearing->id, Direction::Debit, 250, 'XAF'),
                new LedgerEntryInput($wallet->id, Direction::Credit, 250, 'XAF'),
            ],
        ));

        $entryId = DB::table('ledger_entries')->where('transaction_id', $ref->id)->value('id');
        self::assertIsInt($entryId);

        return [$entryId];
    }
}
