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
use RuntimeException;
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

    /**
     * ADR-0076 §2 — the money that leaves a wallet leaves the books with
     * it: the counterparty of a debit is the project's `withdrawal`
     * account, the one that carries the recorded exits.
     *
     * It is NOT `clearing`, which is what the provider holds for the
     * project. A debit booked there would inflate the provider's account
     * with money it never held, and leave the historical project-level
     * account with a balance that no settlement explains — a residue
     * `payment:reclassify-clearing` refuses to move.
     */
    #[Test]
    public function a_wallet_debit_credits_the_withdrawal_account_and_leaves_the_clearing_accounts_untouched(): void
    {
        $partyId = (string) Str::uuid();

        $credited = app(CreditWallet::class)->credit(new CreditWalletCommand(
            self::PROJECT_ID, $partyId, 10_000, 'XAF', null, (string) Str::uuid(),
        ));

        $clearingBefore = $this->clearingBalances();

        app(DebitWallet::class)->debit(new DebitWalletCommand(
            self::PROJECT_ID, $credited->walletId, 4_000, null, (string) Str::uuid(),
        ));

        self::assertSame(
            $clearingBefore,
            $this->clearingBalances(),
            'A wallet debit must not touch any clearing account — it is not the provider\'s money.',
        );

        // The debit is credited on the project-level withdrawal account:
        // a credit, so its net debit is negative.
        self::assertSame(-4_000, $this->netDebit('withdrawal', ''));
    }

    /**
     * The net debit of every `clearing` account of this project, by
     * reference.
     *
     * @return array<string, int>
     */
    private function clearingBalances(): array
    {
        $balances = [];

        foreach (DB::table('ledger_accounts')
            ->where('project_id', self::PROJECT_ID)
            ->where('kind', 'clearing')
            ->pluck('reference') as $reference) {
            if (! is_string($reference)) {
                throw new RuntimeException('A ledger account reference must be a string.');
            }

            $balances[$reference] = $this->netDebit('clearing', $reference);
        }

        return $balances;
    }

    /**
     * The net debit of one account of this project: debits minus credits.
     */
    private function netDebit(string $kind, string $reference): int
    {
        $accountId = DB::table('ledger_accounts')
            ->where('project_id', self::PROJECT_ID)
            ->where('kind', $kind)
            ->where('reference', $reference)
            ->value('id');

        if ($accountId === null) {
            return 0;
        }

        $debit = (int) DB::table('ledger_entries')->where('account_id', $accountId)->where('direction', 'debit')->sum('amount_minor');
        $credit = (int) DB::table('ledger_entries')->where('account_id', $accountId)->where('direction', 'credit')->sum('amount_minor');

        return $debit - $credit;
    }
}
