<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

/**
 * Read model of an operating correction (ADR-0076 §6). Plain scalars
 * only: the enums stay out of `Contracts/` (G3c), their values travel as
 * strings.
 */
final readonly class LedgerAdjustmentView
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $sourceKind,
        public string $sourceReference,
        public string $destinationKind,
        public string $destinationReference,
        public int $amountMinor,
        public string $currency,
        public string $reason,
        /** 'proposed' | 'approved' | 'rejected' */
        public string $status,
        public string $proposedBy,
        public ?string $approvedBy,
        public ?string $ledgerTransactionId,
        /** ADR-0076 §5 — the transaction this correction counter-passes. */
        public ?string $reversesTransactionId,
        public string $createdAt,
        public ?string $approvedAt,
    ) {}
}
