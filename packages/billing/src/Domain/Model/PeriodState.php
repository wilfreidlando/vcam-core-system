<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0072 §1 — where a period stands in its own life.
 *
 * - `upcoming` — created before the deadline, waiting for its invoice
 *   (ADR-0072 §4);
 * - `current` — the period running now; there is at most one per contract;
 * - `closed` — over, never reopened.
 */
enum PeriodState: string
{
    case Upcoming = 'upcoming';
    case Current = 'current';
    case Closed = 'closed';
}
