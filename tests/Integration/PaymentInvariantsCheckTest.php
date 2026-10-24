<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Payment\Contracts\CheckPaymentInvariants;
use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0078 §2 — the hourly financial invariant check, end to end against
 * real PostgreSQL: each violation is found and opens ONE critical money
 * exception; a healthy project reports nothing.
 */
final class PaymentInvariantsCheckTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-0000000078a1';

    #[Test]
    public function a_healthy_project_reports_no_violation(): void
    {
        $this->settledPayment(1_500);

        $report = app(CheckPaymentInvariants::class)->check(2);

        self::assertTrue($report->isClean(), implode("\n", $report->violations));
        self::assertSame(1, $report->paymentsChecked);
        self::assertSame(0, DB::table('money_exceptions')->count());
    }

    #[Test]
    public function a_succeeded_payment_without_settlement_opens_one_critical_exception(): void
    {
        $payment = $this->payment(PaymentStatus::Succeeded, 2_000);
        app(PaymentRepository::class)->create($payment);

        $first = app(CheckPaymentInvariants::class)->check(2);
        $second = app(CheckPaymentInvariants::class)->check(2);

        self::assertCount(1, $first->violations);
        self::assertSame(1, $first->exceptionsOpened);
        self::assertSame(0, $second->exceptionsOpened, 'An unresolved violation is not opened twice.');
        $row = DB::table('money_exceptions')->first();
        self::assertNotNull($row);
        self::assertSame('invariant_violation', $row->kind);
        self::assertSame('critical', $row->severity);
        self::assertSame($payment->id, $row->payment_id);
        self::assertEquals(0, $row->booked_amount_minor);
    }

    #[Test]
    public function a_settlement_without_a_succeeded_payment_is_reported(): void
    {
        $this->settledPayment(1_000);
        $orphanKey = (string) Str::uuid();
        $this->postToLedger('payment_settlement', $orphanKey, 'clearing', 'revenue', 800);

        $report = app(CheckPaymentInvariants::class)->check(2);

        self::assertCount(1, $report->violations);
        self::assertStringContainsString('sans paiement réussi', $report->violations[0]);
    }

    #[Test]
    public function wallets_holding_more_than_was_collected_are_reported(): void
    {
        $this->settledPayment(1_000);
        // Value created out of nothing: revenue -> wallet, without clearing.
        $this->postToLedger('probe_value_creation', (string) Str::uuid(), 'revenue', 'wallet', 5_000);

        $report = app(CheckPaymentInvariants::class)->check(2);

        self::assertNotEmpty(array_filter($report->violations, static fn (string $v): bool => str_contains($v, 'au-dessus de l\'encaissé')));
        self::assertSame(1, DB::table('money_exceptions')->whereRaw("details->>'check' = ?", ['wallets_exceed_clearing'])->count());
    }

    private function settledPayment(int $amount): Payment
    {
        $payment = $this->payment(PaymentStatus::Pending, $amount);
        app(PaymentRepository::class)->create($payment);
        app(PaymentStatusChangeHandler::class)->handle(new PaymentStatusChanged(
            providerCode: 'my-coolpay',
            transactionRef: $payment->providerTransactionRef,
            appTransactionRef: $payment->idempotencyKey,
            status: PaymentStatus::Succeeded,
            confirmedAmountMinor: $amount,
            confirmedCurrency: 'XAF',
        ));

        return $payment;
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

    private function postToLedger(string $type, string $key, string $debitKind, string $creditKind, int $amount): void
    {
        $accounts = app(AccountResolver::class);
        $debit = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, $debitKind, $debitKind === 'wallet' ? 'w-1' : '', 'XAF'));
        $credit = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, $creditKind, $creditKind === 'wallet' ? 'w-1' : '', 'XAF'));

        app(LedgerPoster::class)->post(new PostLedgerTransactionCommand(self::PROJECT_ID, $type, $key, $key, [
            new LedgerEntryInput($debit->id, Direction::Debit, $amount, 'XAF'),
            new LedgerEntryInput($credit->id, Direction::Credit, $amount, 'XAF'),
        ]));
    }
}
