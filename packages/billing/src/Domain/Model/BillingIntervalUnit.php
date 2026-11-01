<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0071 §1 — the unit of a billing interval.
 *
 * `day` and `week` are plain calendar additions; `month` and `year` keep
 * the day of the anchor and clamp it to the end of the month when it does
 * not exist (ADR-0071 §4).
 */
enum BillingIntervalUnit: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
}
