<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Integration\Application;

use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Payment\Contracts\CheckPaymentInvariants;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0078 §2, §3 — a settlement is a transaction whose idempotency
 * key is the payment's id, WHICHEVER path booked it.
 *
 * The control used to look for the settlement transactions by TYPE
 * (`payment_settlement`, `payment_unallocated`) — so the `wallet_credit`
 * of a payment (ADR-0076 §3: « Débit `clearing:<p>` / Crédit
 * `wallet:<partie>` ») was invisible, and a wallet credited for a
 * payment that never succeeded went unnoticed.
 */
final class PaymentSettlementInvariantTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-0000000000d2';

    #[Test]
    public function a_wallet_credit_written_for_a_payment_that_is_not_succeeded_is_a_violation(): void
    {
        $payment = $this->payment(PaymentStatus::Pending, 1_000);
        app(PaymentRepository::class)->create($payment);

        // A settlement keyed on the payment's id — a `wallet_credit`, the
        // type the old type-based filter could not see.
        $this->postWalletCredit($payment->id, 1_000);

        $report = app(CheckPaymentInvariants::class)->check(2);

        self::assertNotEmpty($report->violations);
        self::assertStringContainsString('sans paiement réussi', implode(' ', $report->violations));
        self::assertSame(1, $report->exceptionsOpened);

        // The critical exception is open, and it names the check.
        $exception = DB::table('money_exceptions')->where('project_id', self::PROJECT_ID)->first();
        self::assertNotNull($exception);
        self::assertSame('invariant_violation', $exception->kind);
        self::assertSame('critical', $exception->severity);
        self::assertSame('open', $exception->status);
        $raw = $exception->details;
        $details = is_string($raw) ? json_decode($raw, true) : null;
        self::assertIsArray($details);
        self::assertSame('orphan_settlement', $details['check'] ?? null);
    }

    #[Test]
    public function a_wallet_credit_of_a_succeeded_payment_is_not_a_violation(): void
    {
        $payment = $this->payment(PaymentStatus::Succeeded, 1_000);
        app(PaymentRepository::class)->create($payment);

        $this->postWalletCredit($payment->id, 1_000);

        $report = app(CheckPaymentInvariants::class)->check(2);

        self::assertSame([], $report->violations);
        self::assertSame(0, $report->exceptionsOpened);
    }

    #[Test]
    public function a_wallet_credit_of_an_exception_is_not_a_payment_settlement(): void
    {
        $payment = $this->payment(PaymentStatus::Pending, 1_000);
        app(PaymentRepository::class)->create($payment);

        // The wallet credit of a money exception is keyed on the
        // exception, not on a payment: it is not a settlement.
        $this->postWalletCredit('money_exception:'.(string) Str::uuid().':wallet', 1_000);

        $report = app(CheckPaymentInvariants::class)->check(2);

        self::assertSame([], $report->violations);
        self::assertSame(0, $report->exceptionsOpened);
    }

    private function payment(PaymentStatus $status, int $amount): Payment
    {
        $id = (string) Str::uuid();

        return new Payment(
            id: $id, projectId: self::PROJECT_ID, channel: 'mtn_momo', providerCode: 'my-coolpay',
            idempotencyKey: 'order-'.substr($id, 0, 8), providerTransactionRef: 'tx-'.$id, amount: $amount, currency: 'XAF',
            status: $status, requiresAction: false, actionHint: null, paymentUrl: null, errorMessage: null, returnUrl: null,
            walletRecipientExternalRef: null,
        );
    }

    /**
     * A `wallet_credit` transaction: debit the provider's clearing
     * account, credit the wallet's own account.
     */
    private function postWalletCredit(string $idempotencyKey, int $amount): void
    {
        $accounts = app(AccountResolver::class);
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'clearing', 'my-coolpay', 'XAF'));
        $wallet = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'wallet', (string) Str::uuid(), 'XAF'));

        app(LedgerPoster::class)->post(new PostLedgerTransactionCommand(
            self::PROJECT_ID,
            'wallet_credit',
            null,
            $idempotencyKey,
            [
                new LedgerEntryInput($clearing->id, Direction::Debit, $amount, 'XAF'),
                new LedgerEntryInput($wallet->id, Direction::Credit, $amount, 'XAF'),
            ],
        ));
    }
}
