<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

/**
 * ADR-0078 §5 — how a reconciliation run ended.
 */
enum ReconciliationRunStatus: string
{
    /** Started, not finished yet. */
    case Running = 'running';

    /** Finished, everything checked passed. */
    case Completed = 'completed';

    /** Finished, at least one violation was found. */
    case Failed = 'failed';
}
