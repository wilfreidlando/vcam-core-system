<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use DateTimeImmutable;

/**
 * ADR-0074 §1 — a counter as it stands, for the entitlement read.
 */
final readonly class UsageCounterSnapshot
{
    public function __construct(
        public string $meter,
        public int $used,
        public ?DateTimeImmutable $windowStart,
        public ?DateTimeImmutable $windowEnd,
    ) {}
}
