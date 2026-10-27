<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

/**
 * ADR-0076 §6 — what a person proposes in the console: an amount, the
 * two accounts it moves the money between, a motive, the transaction it
 * reverses, and who proposes.
 */
final readonly class ProposeLedgerAdjustmentCommand
{
    /**
     * @param string $sourceKind The account debited — where the money comes from.
     * @param string $sourceReference The kind-specific identifier, or "" for a project-level account.
     * @param string $destinationKind The account credited — where the money lands.
     * @param string $destinationReference The kind-specific identifier, or "" for a project-level account.
     * @param int $amountMinor Strictly positive, in minor units (G5).
     * @param string $reversesTransactionId ADR-0076 §5 — the transaction this correction counter-passes. It must exist, belong to the same project, and not be a correction itself.
     */
    public function __construct(
        public string $projectId,
        public string $sourceKind,
        public string $sourceReference,
        public string $destinationKind,
        public string $destinationReference,
        public int $amountMinor,
        public string $currency,
        public string $reason,
        public string $proposedBy,
        public string $reversesTransactionId,
    ) {}
}
