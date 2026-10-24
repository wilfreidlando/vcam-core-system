<?php

declare(strict_types=1);

namespace Core\Ledger\Tests\Integration;

use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\FindPostedLedgerKeys;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\ListLedgerTransactions;
use Core\Ledger\Contracts\ListLedgerTransactionsCommand;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Ledger\Contracts\TrialBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** ADR-0076 §4 — the ledger's read side, against real PostgreSQL. */
final class LedgerReadModelTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-0000000076a1';

    private const OTHER_PROJECT = '01a0a1cc-0000-7000-8000-0000000076b2';

    #[Test]
    public function transactions_are_listed_newest_first_with_a_stable_cursor_and_their_lines(): void
    {
        $keys = [];
        for ($i = 0; $i < 5; $i++) {
            $keys[] = $this->book(self::PROJECT_ID, 'payment_settlement', 100 + $i);
        }
        $this->book(self::OTHER_PROJECT, 'payment_settlement', 999);

        $seen = [];
        $cursor = null;
        do {
            $page = app(ListLedgerTransactions::class)->list(new ListLedgerTransactionsCommand(
                projectId: self::PROJECT_ID,
                cursor: $cursor,
                limit: 2,
            ));
            foreach ($page->items as $transaction) {
                $seen[] = $transaction->idempotencyKey;
                self::assertSame(self::PROJECT_ID, $transaction->projectId);
                self::assertCount(2, $transaction->lines);
                self::assertSame('debit', $transaction->lines[0]->direction);
                self::assertSame('clearing', $transaction->lines[0]->accountKind);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        self::assertCount(5, $seen);
        self::assertSame([], array_diff($keys, $seen));
        self::assertCount(5, array_unique($seen));
    }

    #[Test]
    public function the_type_filter_keeps_only_the_requested_types(): void
    {
        $this->book(self::PROJECT_ID, 'payment_settlement', 100);
        $this->book(self::PROJECT_ID, 'wallet_credit', 200);

        $page = app(ListLedgerTransactions::class)->list(new ListLedgerTransactionsCommand(
            projectId: self::PROJECT_ID,
            types: ['wallet_credit'],
        ));

        self::assertCount(1, $page->items);
        self::assertSame('wallet_credit', $page->items[0]->type);
    }

    #[Test]
    public function the_trial_balance_nets_to_zero_per_currency_and_stays_inside_the_project(): void
    {
        $this->book(self::PROJECT_ID, 'payment_settlement', 700);
        $this->book(self::PROJECT_ID, 'payment_settlement', 300);
        $this->book(self::OTHER_PROJECT, 'payment_settlement', 50);

        $balances = app(TrialBalance::class)->forProject(self::PROJECT_ID);

        self::assertCount(2, $balances);
        $net = 0;
        foreach ($balances as $balance) {
            $net += $balance->netDebitMinor();
            if ($balance->kind === 'clearing') {
                self::assertSame(1_000, $balance->debitMinor);
            }
        }
        self::assertSame(0, $net);
    }

    #[Test]
    public function posted_keys_are_found_in_one_call_and_only_for_the_project(): void
    {
        $posted = $this->book(self::PROJECT_ID, 'payment_settlement', 100);
        $other = $this->book(self::OTHER_PROJECT, 'payment_settlement', 100);

        $found = app(FindPostedLedgerKeys::class)->findPosted(self::PROJECT_ID, [$posted, $other, (string) Str::uuid()]);

        self::assertSame([$posted], $found);
    }

    private function book(string $projectId, string $type, int $amount): string
    {
        $accounts = app(AccountResolver::class);
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand($projectId, 'clearing', '', 'XAF'));
        $revenue = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand($projectId, 'revenue', '', 'XAF'));
        $key = (string) Str::uuid();

        app(LedgerPoster::class)->post(new PostLedgerTransactionCommand($projectId, $type, $key, $key, [
            new LedgerEntryInput($clearing->id, Direction::Debit, $amount, 'XAF'),
            new LedgerEntryInput($revenue->id, Direction::Credit, $amount, 'XAF'),
        ]));

        return $key;
    }
}
