<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use Core\Billing\Domain\Exception\InvoicePaymentNotFound;
use DateTimeImmutable;

/**
 * ADR-0073 §2 — one attempt to collect an invoice online.
 *
 * Not a column on the invoice but a **row**: an invoice may have several
 * attempts (a failed one, a canceled one, a second one that succeeds),
 * and a payment that lands late finds its own row — nothing is lost
 * (ADR-0073 §Contexte : « une colonne unique `payment_id` perd la trace
 * d'une tentative antérieure qui aboutit tard »).
 *
 * The `paymentId` is an **opaque reference** to the `payment` module:
 * no foreign key crosses a module boundary (ADR-0033 §5).
 *
 * The amounts are in minor units (G5).
 */
final class InvoicePayment
{
    public function __construct(
        public readonly string $id,
        public readonly string $invoiceId,
        public readonly string $projectId,
        public readonly string $paymentId,
        public readonly int $amountMinor,
        public readonly string $currency,
        public InvoicePaymentStatus $status,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        /**
         * ADR-0094 §10 — what the provider confirmed it collected. `null`
         * when the source confirmed no amount. A confirmed amount that
         * differs from the requested one is **never** imputed (ADR-0077):
         * the attempt then ends in `failed`, with the reason written on
         * it, and the money goes to the money exceptions.
         */
        public ?int $confirmedAmountMinor = null,
        public ?string $failureReason = null,
        public ?DateTimeImmutable $settledAt = null,
        public ?string $ledgerTransactionId = null,
    ) {}

    /**
     * ADR-0073 §2 — the attempt settled and was imputed onto the
     * invoice: the transaction that booked it is kept, and the attempt
     * is closed.
     */
    public function allocate(string $transactionId, DateTimeImmutable $at): void
    {
        if ($this->status !== InvoicePaymentStatus::Pending) {
            throw InvoicePaymentNotFound::becauseNotPending($this->id, $this->status->value);
        }

        $this->status = InvoicePaymentStatus::Succeeded;
        $this->settledAt = $at;
        $this->ledgerTransactionId = $transactionId;
        $this->updatedAt = $at;
    }

    /**
     * ADR-0073 §2 — the attempt did not settle. The reason is kept: an
     * unexplained failure is a support ticket.
     */
    public function fail(string $reason, DateTimeImmutable $at): void
    {
        if ($this->status !== InvoicePaymentStatus::Pending) {
            throw InvoicePaymentNotFound::becauseNotPending($this->id, $this->status->value);
        }

        $this->status = InvoicePaymentStatus::Failed;
        $this->failureReason = $reason;
        $this->updatedAt = $at;
    }

    /**
     * ADR-0073 §2 — the attempt was abandoned.
     */
    public function cancel(string $reason, DateTimeImmutable $at): void
    {
        if ($this->status !== InvoicePaymentStatus::Pending) {
            throw InvoicePaymentNotFound::becauseNotPending($this->id, $this->status->value);
        }

        $this->status = InvoicePaymentStatus::Canceled;
        $this->failureReason = $reason;
        $this->updatedAt = $at;
    }

    public function isPending(): bool
    {
        return $this->status === InvoicePaymentStatus::Pending;
    }
}
