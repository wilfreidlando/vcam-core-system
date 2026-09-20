<?php

declare(strict_types=1);

namespace Core\Wallet\Tests\Integration;

use Core\Wallet\Contracts\CreditWallet;
use Core\Wallet\Contracts\CreditWalletCommand;
use Core\Wallet\Contracts\DebitWallet;
use Core\Wallet\Contracts\DebitWalletCommand;
use Core\Wallet\Domain\Exception\InsufficientFunds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The full Lot 2 path, end to end, against real PostgreSQL: credit and
 * debit a wallet through the bound Application use cases, and verify every
 * side effect this pass promises — the materialised balance, the
 * double-entry ledger rows behind it, and the outbox event — all landed in
 * one atomic unit (ADR-0006, ADR-0032).
 */
final class WalletCreditDebitIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-000000000002';

    #[Test]
    public function crediting_a_new_party_lazily_creates_the_wallet_and_its_ledger_accounts(): void
    {
        $partyId = (string) Str::uuid();

        $view = app(CreditWallet::class)->credit(new CreditWalletCommand(
            self::PROJECT_ID,
            $partyId,
            5_000,
            'XAF',
            null,
            (string) Str::uuid(),
        ));

        self::assertSame(5_000, $view->availableMinor);

        $wallet = DB::table('wallets')->where('id', $view->walletId)->first();
        self::assertNotNull($wallet);
        self::assertSame($partyId, $wallet->party_id);

        self::assertSame(1, DB::table('ledger_accounts')->where('project_id', self::PROJECT_ID)->where('kind', 'wallet')->where('reference', $view->walletId)->count());
        self::assertSame(1, DB::table('ledger_accounts')->where('project_id', self::PROJECT_ID)->where('kind', 'clearing')->count());
    }

    #[Test]
    public function credit_then_debit_leaves_a_balance_matching_the_ledger_entries(): void
    {
        $partyId = (string) Str::uuid();

        $credited = app(CreditWallet::class)->credit(new CreditWalletCommand(
            self::PROJECT_ID, $partyId, 10_000, 'XAF', null, (string) Str::uuid(),
        ));

        $debited = app(DebitWallet::class)->debit(new DebitWalletCommand(
            self::PROJECT_ID, $credited->walletId, 4_000, null, (string) Str::uuid(),
        ));

        self::assertSame(6_000, $debited->availableMinor);

        $walletAccountId = DB::table('ledger_accounts')
            ->where('project_id', self::PROJECT_ID)
            ->where('kind', 'wallet')
            ->where('reference', $credited->walletId)
            ->value('id');

        $entries = DB::table('ledger_entries')->where('account_id', $walletAccountId)->get();
        $derivedBalance = 0;
        foreach ($entries as $entry) {
            self::assertIsNumeric($entry->amount_minor);
            $amountMinor = (int) $entry->amount_minor;
            $derivedBalance += $entry->direction === 'credit' ? $amountMinor : -$amountMinor;
        }

        self::assertSame(6_000, $derivedBalance);
        self::assertSame(2, DB::table('event_outbox')->where('project_id', self::PROJECT_ID)->count());
    }

    #[Test]
    public function debiting_more_than_available_leaves_state_unchanged(): void
    {
        $partyId = (string) Str::uuid();

        $credited = app(CreditWallet::class)->credit(new CreditWalletCommand(
            self::PROJECT_ID, $partyId, 100, 'XAF', null, (string) Str::uuid(),
        ));

        try {
            app(DebitWallet::class)->debit(new DebitWalletCommand(self::PROJECT_ID, $credited->walletId, 101, null, (string) Str::uuid()));
            self::fail('Expected InsufficientFunds.');
        } catch (InsufficientFunds) {
            // expected
        }

        $balance = DB::table('wallet_balances')->where('wallet_id', $credited->walletId)->first();
        self::assertNotNull($balance);
        self::assertIsNumeric($balance->available_minor);
        self::assertSame(100, (int) $balance->available_minor);

        // No debit ledger transaction, no debit outbox event.
        self::assertSame(1, DB::table('event_outbox')->where('project_id', self::PROJECT_ID)->count());
    }

    #[Test]
    public function replaying_a_debit_via_the_use_case_writes_no_second_outbox_event(): void
    {
        $partyId = (string) Str::uuid();
        $idempotencyKey = (string) Str::uuid();

        $credited = app(CreditWallet::class)->credit(new CreditWalletCommand(
            self::PROJECT_ID, $partyId, 1_000, 'XAF', null, (string) Str::uuid(),
        ));

        $first = app(DebitWallet::class)->debit(new DebitWalletCommand(self::PROJECT_ID, $credited->walletId, 400, null, $idempotencyKey));
        $second = app(DebitWallet::class)->debit(new DebitWalletCommand(self::PROJECT_ID, $credited->walletId, 400, null, $idempotencyKey));

        self::assertSame($first->availableMinor, $second->availableMinor);
        self::assertSame(600, $second->availableMinor);

        // One outbox event for the credit, exactly one for the debit — the
        // replay appended none.
        self::assertSame(2, DB::table('event_outbox')->where('project_id', self::PROJECT_ID)->count());
    }
}
