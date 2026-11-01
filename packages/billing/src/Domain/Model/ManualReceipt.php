<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use Core\Billing\Domain\Exception\ManualReceiptApprovalRefused;
use DateTimeImmutable;

/**
 * ADR-0073 §4 — an offline collection: a transfer, a cheque, cash at
 * the counter.
 *
 * The only « activation manuelle » the Core allows (ADR-0073
 * §Alternatives : « activation manuelle sans argent » is rejected). An
 * offline receipt is money that arrived, and it says exactly when, how
 * much and under which reference.
 *
 * Above the project's threshold (`billing_settings.
 * manual_receipt_approval_threshold_minor`), the receipt waits for an
 * explicit **validation** act, audited (ADR-0094 §13).
 *
 * ADR-0095 withdrew the four eyes ADR-0073 §4 prescribed: **one**
 * console account is enough to validate a receipt, the author
 * included. The control that remains is the deliberate act — recorded,
 * audited, and impossible to hide.
 */
final class ManualReceipt
{
    public function __construct(
        public readonly string $id,
        public readonly string $projectId,
        public readonly string $invoiceId,
        public readonly ManualReceiptMethod $method,
        public readonly string $reference,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly DateTimeImmutable $receivedAt,
        /** The console account that recorded the receipt. */
        public readonly string $recordedBy,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        /** The second console account that approved it, if any. */
        public ?string $approvedBy = null,
        public ?DateTimeImmutable $approvedAt = null,
        public ManualReceiptStatus $status = ManualReceiptStatus::Pending,
        public ?string $evidenceRef = null,
        public ?string $note = null,
        public ?string $ledgerTransactionId = null,
    ) {}

    /**
     * ADR-0073 §4, ADR-0095 — the receipt is validated: it becomes a
     * bookkeeping fact.
     *
     * **One** console account is enough (ADR-0095 withdrew the four
     * eyes): what the Core asks for is a deliberate act, recorded and
     * audited — not a second pair of hands.
     */
    public function approve(string $approver, DateTimeImmutable $at, ?string $transactionId = null): void
    {
        if ($this->status !== ManualReceiptStatus::Pending) {
            throw ManualReceiptApprovalRefused::becauseNotPending($this->id, $this->status->value);
        }

        $this->status = ManualReceiptStatus::Approved;
        $this->approvedBy = $approver;
        $this->approvedAt = $at;
        $this->ledgerTransactionId = $transactionId;
        $this->updatedAt = $at;
    }

    /**
     * ADR-0094 §13 — the validation is refused: nothing is booked, the
     * trail stays.
     */
    public function reject(string $approver, DateTimeImmutable $at): void
    {
        if ($this->status !== ManualReceiptStatus::Pending) {
            throw ManualReceiptApprovalRefused::becauseNotPending($this->id, $this->status->value);
        }

        $this->status = ManualReceiptStatus::Rejected;
        $this->approvedBy = $approver;
        $this->approvedAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * The receipt becomes a bookkeeping fact at the recording itself —
     * below the project's threshold, no second account is needed
     * (ADR-0094 §13).
     */
    public function accountAtRecording(DateTimeImmutable $at, string $transactionId): void
    {
        $this->status = ManualReceiptStatus::Approved;
        $this->approvedAt = $at;
        $this->ledgerTransactionId = $transactionId;
        $this->updatedAt = $at;
    }

    public function isPending(): bool
    {
        return $this->status === ManualReceiptStatus::Pending;
    }
}
