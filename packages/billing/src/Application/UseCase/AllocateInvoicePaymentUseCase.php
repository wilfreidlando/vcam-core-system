<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\InvoiceLedger;
use Core\Billing\Application\Support\InvoiceEvents;
use Core\Billing\Application\Support\InvoicePaymentViews;
use Core\Billing\Contracts\InvoicePaymentView;
use Core\Billing\Domain\Exception\InvoicePaymentNotFound;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Model\Invoice;
use Core\Billing\Domain\Model\InvoicePayment;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\InvoicePaymentRepository;
use Core\Billing\Domain\Port\InvoiceRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Payment\Contracts\RecordMoneyException;
use Core\Payment\Contracts\RecordMoneyExceptionCommand;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;
use DateTimeImmutable;

/**
 * ADR-0073 §2, §3 — the collection that settled: the money is imputed
 * onto the invoice.
 *
 * `billing` learns that a payment settled by consuming the
 * `payment.status_changed` event of the outbox and finding **its own**
 * attempt by `payment_id` (ADR-0073 §2). The transition is
 * **conditional** — `WHERE status = 'pending'` — so a replay of the same
 * event moves nothing (ADR-0073 §2).
 *
 * Three outcomes, and none of them loses money:
 *
 *  - the amount is the one that was asked for → the money is imputed:
 *    Débit `collections` / Crédit `customer_receivable:<client>` (the
 *    `payment` module already booked Débit `clearing` / Crédit
 *    `collections`, ADR-0076 §3);
 *  - the amount or the currency differs → **nothing is imputed**
 *    (ADR-0094 §10, ADR-0077): the `payment` module has already moved
 *    the money to `payment_exceptions` and opened the money exception;
 *  - the invoice was settled in the meantime → the money is **not lost**:
 *    it moves from `collections` to `payment_exceptions` and an
 *    overpayment exception is opened (ADR-0073 §2).
 */
final readonly class AllocateInvoicePaymentUseCase
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private InvoicePaymentRepository $payments,
        private InvoiceRepository $invoices,
        private CustomerRepository $customers,
        private InvoiceLedger $ledger,
        private RecordMoneyException $exceptions,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    /**
     * @param string $paymentId the payment that settled
     * @param int|null $confirmedAmountMinor what the provider confirmed it collected (ADR-0077)
     * @param string|null $confirmedCurrency the currency the provider confirmed
     */
    public function allocate(
        string $paymentId,
        ?int $confirmedAmountMinor,
        ?string $confirmedCurrency,
    ): ?InvoicePaymentView {
        return $this->unitOfWork->transactional(function () use ($paymentId, $confirmedAmountMinor, $confirmedCurrency): ?InvoicePaymentView {
            $now = $this->clock->now();

            $attempt = $this->payments->findByPaymentId($paymentId);

            // Not one of ours, or already moved: a replay does nothing.
            if ($attempt === null || ! $attempt->isPending()) {
                return null;
            }

            $invoice = $this->invoices->findById($attempt->invoiceId);

            if ($invoice === null) {
                return null;
            }

            // G13 — the isolation of the projects: an attempt that
            // belongs to another project is never imputed on this
            // invoice.
            if ($attempt->projectId !== $invoice->projectId) {
                return null;
            }

            // ADR-0094 §10 / ADR-0077 — a settlement whose currency
            // differs is never imputed. The `payment` module has already
            // booked it to `payment_exceptions` and opened the money
            // exception: nothing is left to allocate.
            if ($confirmedCurrency !== null && strtoupper($confirmedCurrency) !== strtoupper($attempt->currency)) {
                $this->payments->markFailed($attempt->id, 'currency_mismatch: '.$confirmedCurrency, $now);

                return null;
            }

            // ADR-0094 §10 — a partial settlement is never imputed
            // either.
            if ($confirmedAmountMinor !== null && $confirmedAmountMinor !== $attempt->amountMinor) {
                $this->payments->markFailed($attempt->id, 'amount_mismatch: '.$confirmedAmountMinor, $now);

                return null;
            }

            // What is still due on the invoice — it may have shrunk
            // since the attempt was started.
            $remaining = $invoice->remainingMinor();
            $allocatable = min($remaining, $attempt->amountMinor);

            if ($allocatable <= 0) {
                return $this->postOverpayment($attempt, $invoice, $attempt->amountMinor, $now);
            }

            // The imputation itself: Débit `collections` / Crédit
            // `customer_receivable:<client>` (ADR-0073 §3).
            $transactionId = $this->ledger->postAllocation($attempt, $invoice, $allocatable);

            // The conditional transition: a replay finds nothing to move
            // (ADR-0073 §2). It **raises** rather than returning: the
            // ledger entry is already written, and validating the
            // transaction here would book the money on the receivable
            // without crediting the invoice — the receivables invariant
            // would be false. The rollback undoes the entry.
            if (! $this->payments->markSucceeded($attempt->id, $transactionId, $now, $confirmedAmountMinor)) {
                throw InvoicePaymentNotFound::becauseNotPending($attempt->id, 'moved by another allocation');
            }

            $invoice->credit($allocatable, $now);
            $invoice->version++;
            $this->invoices->save($invoice);

            // What is left of the payment when it covers more than what
            // was due is **not lost** (ADR-0073 §2).
            $surplus = $attempt->amountMinor - $allocatable;

            if ($surplus > 0) {
                $this->ledger->postOverpayment($attempt, $surplus);
                $this->openOverpaymentException($attempt, $invoice, $surplus);
            }

            $this->publishPaid($invoice, $now);

            $settled = $this->payments->findById($attempt->id);

            return $settled === null ? null : InvoicePaymentViews::from($settled);
        });
    }

    /**
     * ADR-0073 §2 — a payment that lands on an invoice that is already
     * settled: the money moves to the money exceptions and an
     * overpayment is opened. Nothing is silently kept.
     */
    private function postOverpayment(
        InvoicePayment $attempt,
        Invoice $invoice,
        int $amountMinor,
        DateTimeImmutable $now,
    ): ?InvoicePaymentView {
        $transactionId = $this->ledger->postOverpayment($attempt, $amountMinor);

        if (! $this->payments->markSucceeded($attempt->id, $transactionId, $now, $amountMinor)) {
            throw InvoicePaymentNotFound::becauseNotPending($attempt->id, 'moved by another allocation');
        }

        $this->openOverpaymentException($attempt, $invoice, $amountMinor);

        $settled = $this->payments->findById($attempt->id);

        return $settled === null ? null : InvoicePaymentViews::from($settled);
    }

    private function openOverpaymentException(
        InvoicePayment $attempt,
        Invoice $invoice,
        int $amountMinor,
    ): void {
        $this->exceptions->record(new RecordMoneyExceptionCommand(
            projectId: $attempt->projectId,
            kind: 'overpayment',
            severity: 'medium',
            amountMinor: $amountMinor,
            currency: $attempt->currency,
            bookedAmountMinor: $amountMinor,
            paymentId: $attempt->paymentId,
            subjectRef: $invoice->id,
            summary: sprintf(
                'Payment %s of %d %s arrived on invoice %s, which was already settled: the amount was moved to the money exceptions.',
                $attempt->paymentId,
                $amountMinor,
                $attempt->currency,
                $invoice->number ?? $invoice->id,
            ),
            details: [
                'invoice_id' => $invoice->id,
                'attempt_id' => $attempt->id,
                'amount_minor' => $amountMinor,
                'currency' => $attempt->currency,
            ],
        ));
    }

    /**
     * ADR-0073 §6 — the invoice is settled: the event says so.
     */
    private function publishPaid(Invoice $invoice, DateTimeImmutable $now): void
    {
        if ($invoice->status->value !== 'paid') {
            return;
        }

        $customer = $this->customers->findById($invoice->customerId);
        $customerRef = $customer instanceof Customer ? $customer->externalRef : '';

        $this->outbox->append(new DomainEvent(
            $this->identifier->generate(),
            $invoice->projectId,
            'invoice.paid',
            InvoiceEvents::payload($invoice, $customerRef),
            null,
            $now,
        ));
    }
}
