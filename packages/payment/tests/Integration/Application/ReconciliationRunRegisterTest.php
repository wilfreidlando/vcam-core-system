<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Integration\Application;

use Core\Payment\Contracts\CheckPaymentInvariants;
use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Model\ReconciliationRun;
use Core\Payment\Domain\Model\ReconciliationRunStatus;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\Domain\Port\ReconciliationRunRepository;
use Core\Payment\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0078 §5 — « On doit pouvoir prouver que le contrôle a tourné et
 * qu'il a passé. » Every pass of level A leaves a trace in the
 * reconciliation register — counters and identifiers only, never anything
 * personal (ADR-0083 §4).
 */
final class ReconciliationRunRegisterTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-0000000000c5';

    #[Test]
    public function a_clean_pass_is_traced_as_completed(): void
    {
        $this->settledPayment(1_500);

        $report = app(CheckPaymentInvariants::class)->check(2);

        $run = $this->runOfThisProject();
        self::assertNotNull($run);
        self::assertSame(ReconciliationRunStatus::Completed, $run->status);
        self::assertNull($run->providerCode, 'level A is not provider-specific');
        self::assertNotNull($run->finishedAt);
        self::assertContains($run->id, $report->runIds);

        // The trace really is in the table.
        self::assertSame(1, DB::table('reconciliation_runs')
            ->where('project_id', self::PROJECT_ID)
            ->where('level', 'invariants')
            ->count());

        // The settlement was written: the project has a currency, so
        // the trial balance and the wallets/clearing comparison were
        // really evaluated — four invariants, none skipped.
        self::assertSame(4, $run->stats['invariants_checked']);
        self::assertSame([], $run->stats['invariants_skipped']);
    }

    #[Test]
    public function a_pass_with_a_violation_is_traced_as_failed(): void
    {
        // A succeeded payment with no settlement at all: the invariant is
        // broken, and the pass must say so.
        app(PaymentRepository::class)->create($this->payment(PaymentStatus::Succeeded, 2_000));

        app(CheckPaymentInvariants::class)->check(2);

        $run = $this->runOfThisProject();
        self::assertNotNull($run);
        self::assertSame(ReconciliationRunStatus::Failed, $run->status);

        $stats = $run->stats;
        self::assertIsInt($stats['invariants_checked']);
        // This project never wrote anything to the ledger: it has no
        // currency to check its trial balance in, and no wallet to
        // compare with the collections. Those two invariants were NOT
        // evaluated — the trace says so instead of announcing a fixed
        // number of checks.
        self::assertSame(2, $stats['invariants_checked']);
        self::assertSame(['trial_balance', 'wallets_exceed_clearing'], $stats['invariants_skipped']);
        self::assertIsInt($stats['violations']);
        self::assertGreaterThan(0, $stats['violations']);
        self::assertIsInt($stats['exceptions_opened']);
        self::assertGreaterThan(0, $stats['exceptions_opened']);
        self::assertIsArray($stats['exception_ids']);
        self::assertCount(1, $stats['exception_ids']);
    }

    #[Test]
    public function the_trace_holds_counters_and_identifiers_only(): void
    {
        app(PaymentRepository::class)->create($this->payment(PaymentStatus::Succeeded, 2_000));

        app(CheckPaymentInvariants::class)->check(2);

        $run = $this->runOfThisProject();
        self::assertNotNull($run);

        $stats = $run->stats;

        // Only counters and identifiers — never a name, a reference, an
        // amount tied to a person (ADR-0083 §4). (A jsonb column does not
        // keep the insertion order of its keys.)
        self::assertEqualsCanonicalizing(
            ['invariants_checked', 'invariants_skipped', 'violations', 'exceptions_opened', 'exception_ids'],
            array_keys($stats),
        );

        self::assertIsArray($stats['exception_ids']);
        foreach ($stats['exception_ids'] as $exceptionId) {
            self::assertIsString($exceptionId);
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
                $exceptionId,
            );
        }
    }

    #[Test]
    public function every_pass_leaves_a_trace(): void
    {
        $this->settledPayment(1_500);

        app(CheckPaymentInvariants::class)->check(2);
        app(CheckPaymentInvariants::class)->check(2);

        self::assertGreaterThanOrEqual(
            2,
            DB::table('reconciliation_runs')->where('project_id', self::PROJECT_ID)->where('level', 'invariants')->count(),
        );
    }

    private function runOfThisProject(): ?ReconciliationRun
    {
        foreach (app(ReconciliationRunRepository::class)->findLatest('invariants', 50) as $run) {
            if ($run->projectId === self::PROJECT_ID) {
                return $run;
            }
        }

        return null;
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
}
