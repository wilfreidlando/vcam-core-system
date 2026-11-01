<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §4 — the recording of an offline collection.
 *
 * The method is the canonical `ManualReceiptMethod` value as a plain
 * string — never the enum itself (G3c). The amount is in minor units
 * (G5) and the date an ISO 8601 string.
 */
final readonly class RecordManualReceiptCommand
{
    public function __construct(
        public string $projectId,
        public string $invoiceId,
        /** 'bank_transfer' | 'cash' | 'cheque' | 'other'. */
        public string $method,
        /** The reference of the transfer, the receipt number… — mandatory. */
        public string $reference,
        public int $amountMinor,
        public string $currency,
        /** ISO 8601 — when the money actually arrived. */
        public string $receivedAt,
        /** The console account that records the receipt. */
        public string $recordedBy,
        public ?string $evidenceRef = null,
        public ?string $note = null,
        public string $actor = '',
        public ?string $correlationId = null,
    ) {}
}
