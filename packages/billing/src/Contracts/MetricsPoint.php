<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * One day of one currency of the indicator history (ADR-0080 §2).
 */
final readonly class MetricsPoint
{
    public function __construct(
        public string $day,
        public string $currency,
        public int $mrrMinor,
        public int $collectedMinor,
        public int $receivablesMinor,
        public int $activeCustomers,
        public int $trialing,
        public int $pastDue,
        public int $newThisMonth,
        public int $lostThisMonth,
        /**
         * ADR-0080 §3 — the instant the row was **computed**, which is not
         * necessarily its day: a replay over a range fills the days the
         * nightly job missed with the figures of the moment of the pass.
         */
        public string $computedAt = '',
    ) {}
}
