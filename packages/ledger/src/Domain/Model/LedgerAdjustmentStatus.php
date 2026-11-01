<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Model;

/**
 * ADR-0076 §6 — the life of an operating correction: proposed by a
 * person, then either validated (and written to the ledger) or refused.
 */
enum LedgerAdjustmentStatus: string
{
    /** Waiting for a second pair of eyes (ADR-0081 S-15). */
    case Proposed = 'proposed';

    /** Validated: the correction is now a ledger transaction. */
    case Approved = 'approved';

    /** Refused: nothing was written to the ledger. */
    case Rejected = 'rejected';
}
