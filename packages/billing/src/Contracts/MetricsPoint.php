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
    ) {}
}
