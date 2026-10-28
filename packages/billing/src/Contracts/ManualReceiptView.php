<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §4 — an offline collection.
 *
 * The method is the canonical `ManualReceiptMethod` value as a plain
 * string — never the enum itself (G3c) — and the status the canonical
 * `ManualReceiptStatus` one.
 *
 * The dates are ISO 8601 strings and the amount is in minor units (G5).
 */
final readonly class ManualReceiptView
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $invoiceId,
        /** 'bank_transfer' | 'cash' | 'cheque' | 'other'. */
        public string $method,
        public string $reference,
        public int $amountMinor,
        public string $currency,
        public string $receivedAt,
        /** The console account that recorded the receipt. */
        public string $recordedBy,
        /** 'pending' | 'approved' | 'rejected'. */
        public string $status,
        /** The console account that approved it, when a second one did. */
        public ?string $approvedBy = null,
        public ?string $approvedAt = null,
        public ?string $evidenceRef = null,
        public ?string $note = null,
        public ?string $createdAt = null,
    ) {}
}
