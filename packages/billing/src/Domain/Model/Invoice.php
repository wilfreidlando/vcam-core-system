<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use Core\Billing\Domain\Exception\InvalidInvoiceTransition;
use DateTimeImmutable;

/**
 * ADR-0073 §1 — the invoice, the document that carries a receivable.
 *
 * An invoice is a **document**: once issued, its number, its lines, its
 * amounts and the snapshot of the customer are frozen — by the database
 * itself, not by a convention. Only its status and what has been paid of
 * it move afterwards.
 *
 * The amounts are in minor units and its currency is a `char(3)`
 * (G5): the Core never computes a float and never knows a tax regime.
 *
 * The snapshot is the customer as it was at the issuance: the personal
 * values are **encrypted** (ADR-0083 §2, ADR-0094 §2), the reference is
 * the project's own opaque key.
 */
final class Invoice
{
    /**
     * @param array<string, mixed> $customerSnapshot the customer as it was at the issuance — the personal
     *                                               values are ciphertexts, never clear
     */
    public function __construct(
        public readonly string $id,
        public readonly string $projectId,
        public readonly string $customerId,
        public readonly ?string $subscriptionId,
        public readonly ?string $periodId,
        public ?string $number,
        public InvoiceStatus $status,
        public readonly string $currency,
        public int $subtotalMinor,
        public int $taxMinor,
        public int $totalMinor,
        public int $amountPaidMinor,
        public array $customerSnapshot,
        public ?DateTimeImmutable $dueAt,
        public ?DateTimeImmutable $issuedAt,
        public ?DateTimeImmutable $paidAt,
        public ?DateTimeImmutable $voidedAt,
        public ?string $voidReason,
        /**
         * ADR-0094 §12 — the instant of the write-off. `updated_at` would
         * move again on any later touch; this one is the act itself.
         */
        public ?DateTimeImmutable $lostAt,
        /**
         * ADR-0094 §3 — the id of the issuance transaction in the
         * ledger: the void counter-passes it (ADR-0076 §5).
         */
        public ?string $ledgerTransactionId,
        public int $version,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * ADR-0073 §1 — the issuance: the number is attributed under the
     * lock of the sequence, and from this instant the document is
     * frozen.
     */
    public function issue(string $number, DateTimeImmutable $at): void
    {
        if ($this->status !== InvoiceStatus::Draft) {
            throw InvalidInvoiceTransition::becauseNotAllowed($this->status->value, InvoiceStatus::Open->value);
        }

        $this->number = $number;
        $this->status = InvoiceStatus::Open;
        $this->issuedAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * ADR-0094 §3 — the invoice keeps the reference of the transaction
     * that booked it, so that its cancellation can counter-pass **that**
     * transaction and not another one.
     */
    public function attachLedgerTransaction(string $transactionId): void
    {
        $this->ledgerTransactionId = $transactionId;
    }

    /**
     * ADR-0073 §3 — the void is the counter-passation of the issuance,
     * and it is only honest when nothing has been received.
     */
    public function void(string $reason, DateTimeImmutable $at): void
    {
        if ($this->status !== InvoiceStatus::Open) {
            throw InvalidInvoiceTransition::becauseNotAllowed($this->status->value, InvoiceStatus::Void->value);
        }

        if ($this->amountPaidMinor > 0) {
            throw InvalidInvoiceTransition::becauseSomethingWasPaid($this->amountPaidMinor);
        }

        $this->status = InvoiceStatus::Void;
        $this->voidReason = $reason;
        $this->voidedAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * ADR-0073 §3 — the write-off: the receivable became a bad debt.
     *
     * An act of the operator, never an effect of the time (ADR-0094 §12).
     * The instant of the act is kept: `lost_at` is the date the
     * receivable screen shows, and `updated_at` would move again.
     */
    public function markUncollectible(DateTimeImmutable $at): void
    {
        if ($this->status !== InvoiceStatus::Open) {
            throw InvalidInvoiceTransition::becauseNotAllowed($this->status->value, InvoiceStatus::Uncollectible->value);
        }

        // ADR-0073 §3 — the write-off moves the **whole** receivable to
        // `bad_debt`. A partly-paid invoice would be credited twice: once
        // for what was received, once for the total. `void()` refuses the
        // same situation; the write-off refuses it too.
        if ($this->amountPaidMinor > 0) {
            throw InvalidInvoiceTransition::becauseSomethingWasPaid($this->amountPaidMinor);
        }

        $this->status = InvoiceStatus::Uncollectible;
        $this->lostAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * ADR-0073 §3 — the invoice is settled: what is received covers the
     * whole of what is due.
     */
    public function markPaid(DateTimeImmutable $at): void
    {
        if ($this->status !== InvoiceStatus::Open) {
            throw InvalidInvoiceTransition::becauseNotAllowed($this->status->value, InvoiceStatus::Paid->value);
        }

        if ($this->amountPaidMinor !== $this->totalMinor) {
            throw InvalidInvoiceTransition::becauseNotAllowed($this->status->value, InvoiceStatus::Paid->value);
        }

        $this->status = InvoiceStatus::Paid;
        $this->paidAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * ADR-0073 §2 — what is received is credited to the invoice. Only a
     * **total** allocation moves it (ADR-0094 §10): a partial payment is
     * never imputed automatically (ADR-0077).
     */
    public function credit(int $amountMinor, DateTimeImmutable $at): void
    {
        if ($amountMinor <= 0) {
            throw InvalidInvoiceTransition::becauseNotAllowed($this->status->value, 'a credit of nothing');
        }

        $this->amountPaidMinor += $amountMinor;
        $this->updatedAt = $at;

        if ($this->amountPaidMinor >= $this->totalMinor) {
            $this->status = InvoiceStatus::Paid;
            $this->paidAt = $at;
        }
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::Paid;
    }

    /**
     * What is still due on the invoice — never negative.
     */
    public function remainingMinor(): int
    {
        return max(0, $this->totalMinor - $this->amountPaidMinor);
    }

    /**
     * The invoice is settled when what is received covers what is due.
     */
    public function isSettled(): bool
    {
        return $this->amountPaidMinor >= $this->totalMinor;
    }
}
