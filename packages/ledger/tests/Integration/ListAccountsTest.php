<?php

declare(strict_types=1);

namespace Core\Ledger\Tests\Integration;

use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\ListAccounts;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Support\Access\AccessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0125 §6 — the accounts of a project, ALL of them.
 *
 * The trial balance only lists the accounts that carry a movement; the
 * chart of accounts lists them all, the ones that never moved included.
 */
final class ListAccountsTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-0000000125a1';

    private const OTHER_PROJECT = '01a0a1cc-0000-7000-8000-0000000125b2';

    #[Test]
    public function an_account_that_never_moved_is_listed_all_the_same(): void
    {
        $accounts = app(AccountResolver::class);
        $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'wallet', 'wallet-1', 'XAF'));
        $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'revenue', '', 'XAF'));

        $listed = app(ListAccounts::class)->accountsOf(self::PROJECT_ID, AccessScope::forProject(self::PROJECT_ID));

        self::assertCount(2, $listed);

        $wallet = null;
        foreach ($listed as $account) {
            if ($account->kind === 'wallet') {
                $wallet = $account;
            }
        }

        self::assertNotNull($wallet);
        self::assertSame('wallet-1', $wallet->reference);
        self::assertSame('XAF', $wallet->currency);
        self::assertSame(0, $wallet->debitMinor);
        self::assertSame(0, $wallet->creditMinor);
        self::assertSame(0, $wallet->netDebitMinor());
    }

    #[Test]
    public function the_accounts_that_moved_carry_their_amounts(): void
    {
        $accounts = app(AccountResolver::class);
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'clearing', 'mycoolpay', 'XAF'));
        $revenue = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'revenue', '', 'XAF'));

        app(LedgerPoster::class)->post(new PostLedgerTransactionCommand(
            projectId: self::PROJECT_ID,
            type: 'payment_collection',
            externalRef: 'pay-1',
            idempotencyKey: (string) Str::uuid(),
            entries: [
                new LedgerEntryInput($clearing->id, Direction::Debit, 50_000, 'XAF'),
                new LedgerEntryInput($revenue->id, Direction::Credit, 50_000, 'XAF'),
            ],
        ));

        $listed = app(ListAccounts::class)->accountsOf(self::PROJECT_ID, AccessScope::forProject(self::PROJECT_ID));

        self::assertCount(2, $listed);

        foreach ($listed as $account) {
            if ($account->kind === 'clearing') {
                self::assertSame('mycoolpay', $account->reference);
                self::assertSame(50_000, $account->debitMinor);
                self::assertSame(0, $account->creditMinor);
                self::assertSame(50_000, $account->netDebitMinor());
            }

            if ($account->kind === 'revenue') {
                self::assertSame(0, $account->debitMinor);
                self::assertSame(50_000, $account->creditMinor);
                self::assertSame(-50_000, $account->netDebitMinor());
            }
        }
    }

    #[Test]
    public function the_accounts_of_another_project_are_never_listed(): void
    {
        $accounts = app(AccountResolver::class);
        $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'revenue', '', 'XAF'));
        $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::OTHER_PROJECT, 'revenue', '', 'XAF'));

        $listed = app(ListAccounts::class)->accountsOf(self::PROJECT_ID, AccessScope::forProject(self::PROJECT_ID));

        self::assertCount(1, $listed);
        self::assertSame('revenue', $listed[0]->kind);

        // A scope that does not open the project reads nothing at all.
        $denied = app(ListAccounts::class)->accountsOf(self::PROJECT_ID, AccessScope::none());

        self::assertSame([], $denied);
    }
}
