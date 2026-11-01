<?php

declare(strict_types=1);

namespace Core\Ledger\Tests\Integration;

use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\AccountStatement;
use Core\Ledger\Contracts\AccountStatementCommand;
use Core\Ledger\Contracts\AccountStatementPage;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0076 §4 — the account statement: the balance the account opened
 * the period on, the lines of the period, the balance it closes on.
 *
 * The entries are inserted with an explicit `created_at`: the ledger's
 * entries are immutable (a trigger refuses any UPDATE), so a statement
 * that must tell one day from another needs its history dated.
 */
final class AccountStatementTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-0000000076c1';

    #[Test]
    public function the_opening_balance_plus_the_period_equals_the_closing_balance(): void
    {
        $this->postEntry('debit', 1_000, $this->at('-3 days'));
        $this->postEntry('credit', 300, $this->at('-1 day +12 hours'));
        $this->postEntry('debit', 500, $this->at('+1 day'));

        $page = $this->statement($this->at('-1 day'), $this->at('+1 day'));

        // Strictly before `from`: the 1 000 debit.
        self::assertSame(1_000, $page->openingBalanceMinor);
        // `[from, to)`: the 300 credit, and nothing else.
        self::assertCount(1, $page->lines);
        self::assertSame('credit', $page->lines[0]->direction);
        self::assertSame(300, $page->lines[0]->amountMinor);
        self::assertSame(1_000 - 300, $page->closingBalanceMinor);
    }

    #[Test]
    public function the_period_is_exclusive_of_its_upper_bound(): void
    {
        $this->postEntry('debit', 400, $this->at('+1 day'));

        $page = $this->statement($this->at('-1 day'), $this->at('+1 day'));

        self::assertSame([], $page->lines);
        self::assertSame(0, $page->openingBalanceMinor);
        self::assertSame(0, $page->closingBalanceMinor);
    }

    #[Test]
    public function the_running_balance_follows_the_lines_and_the_cursor_neither_skips_nor_repeats(): void
    {
        $this->postEntry('debit', 1_000, $this->at('-3 days'));
        for ($i = 0; $i < 5; $i++) {
            $this->postEntry('debit', 100 * ($i + 1), $this->at('-1 day +'.$i.' hours'));
        }

        $seen = [];
        $balances = [];
        $cursor = null;

        do {
            $page = $this->statement($this->at('-2 days'), $this->at('+2 days'), $cursor, limit: 2);

            foreach ($page->lines as $line) {
                $seen[] = $line->entryId;
                $balances[] = $line->balanceAfterMinor;
            }

            // The opening balance and the closing balance describe the
            // WHOLE period: they do not depend on the page being read.
            self::assertSame(1_000, $page->openingBalanceMinor);
            self::assertSame(2_500, $page->closingBalanceMinor);

            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        self::assertCount(5, $seen);
        self::assertCount(5, array_unique($seen));
        // Oldest first, and the running balance ends on the closing one.
        self::assertSame([1_100, 1_300, 1_600, 2_000, 2_500], $balances);
    }

    #[Test]
    public function an_account_that_never_existed_opens_and_closes_on_zero(): void
    {
        $page = $this->statement($this->at('-1 day'), $this->at('+1 day'));

        self::assertSame([], $page->lines);
        self::assertNull($page->nextCursor);
        self::assertSame(0, $page->openingBalanceMinor);
        self::assertSame(0, $page->closingBalanceMinor);
        self::assertSame('XAF', $page->currency);
    }

    #[Test]
    public function two_entries_of_the_same_instant_are_neither_skipped_nor_repeated(): void
    {
        // The two entries share their instant: the cursor is
        // `(created_at, id)`, so the second one is only reached through
        // the `created_at = X AND id > Y` branch — the case a naive
        // cursor skips or repeats.
        $at = $this->at('-1 day');
        $this->postEntry('debit', 1_000, $at);
        $this->postEntry('credit', 400, $at);

        $seen = [];
        $balances = [];
        $cursor = null;

        do {
            $page = $this->statement($this->at('-2 days'), $this->at('+2 days'), $cursor, limit: 1);

            foreach ($page->lines as $line) {
                $seen[] = $line->entryId;
                $balances[] = $line->balanceAfterMinor;
            }

            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        self::assertCount(2, $seen);
        self::assertCount(2, array_unique($seen));
        // The debit comes first, then the credit: the balance goes
        // 1 000 then 600 — no line repeated, no line skipped.
        self::assertSame([1_000, 600], $balances);
    }

    #[Test]
    public function the_currency_is_normalized(): void
    {
        $this->postEntry('debit', 1_000, $this->at('-1 day'));

        $lower = $this->statementInCurrency('xaf', $this->at('-2 days'), $this->at('+2 days'));
        $upper = $this->statementInCurrency('XAF', $this->at('-2 days'), $this->at('+2 days'));

        // A lower-case currency would match no account at all and return
        // an EMPTY statement — which reads as "this account never moved".
        self::assertCount(1, $lower->lines);
        self::assertCount(1, $upper->lines);
        self::assertSame($upper->lines[0]->entryId, $lower->lines[0]->entryId);
        self::assertSame($upper->closingBalanceMinor, $lower->closingBalanceMinor);
        self::assertSame('XAF', $lower->currency);
    }

    #[Test]
    public function an_empty_currency_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AccountStatementCommand(
            projectId: self::PROJECT_ID,
            kind: 'clearing',
            reference: '',
            currency: '   ',
            from: $this->at('-1 day'),
            to: $this->at('+1 day'),
        );
    }

    private function statement(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $cursor = null,
        int $limit = 50,
    ): AccountStatementPage {
        return $this->statementInCurrency('XAF', $from, $to, $cursor, $limit);
    }

    private function statementInCurrency(
        string $currency,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $cursor = null,
        int $limit = 50,
    ): AccountStatementPage {
        return app(AccountStatement::class)->statement(new AccountStatementCommand(
            projectId: self::PROJECT_ID,
            kind: 'clearing',
            reference: '',
            currency: $currency,
            from: $from,
            to: $to,
            cursor: $cursor,
            limit: $limit,
        ));
    }

    /**
     * One balanced transaction of the `clearing` account, dated.
     */
    private function postEntry(string $direction, int $amount, DateTimeImmutable $at): void
    {
        $accounts = app(AccountResolver::class);
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'clearing', '', 'XAF'));
        $revenue = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'revenue', '', 'XAF'));

        $transactionId = (string) Str::uuid();
        $stamp = $at->format(DateTimeInterface::ATOM);

        DB::table('ledger_transactions')->insert([
            'id' => $transactionId,
            'project_id' => self::PROJECT_ID,
            'type' => 'payment_settlement',
            'external_ref' => null,
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => $stamp,
        ]);

        DB::table('ledger_entries')->insert([
            [
                'transaction_id' => $transactionId,
                'account_id' => $clearing->id,
                'direction' => $direction,
                'amount_minor' => $amount,
                'currency' => 'XAF',
                'created_at' => $stamp,
            ],
            [
                'transaction_id' => $transactionId,
                'account_id' => $revenue->id,
                'direction' => $direction === 'debit' ? 'credit' : 'debit',
                'amount_minor' => $amount,
                'currency' => 'XAF',
                'created_at' => $stamp,
            ],
        ]);
    }

    /**
     * A fixed base date: the statement is about periods, and a period
     * that moves with the clock is a period nobody can reason about.
     */
    private function at(string $offset): DateTimeImmutable
    {
        return (new DateTimeImmutable('2026-10-01 00:00:00', new DateTimeZone('UTC')))->modify($offset);
    }
}
