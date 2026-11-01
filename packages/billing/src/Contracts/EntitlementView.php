<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0074 §4 — one feature of the plan, with its counter when metered.
 */
final readonly class EntitlementView
{
    public function __construct(
        public string $key,
        public string $type,
        public ?bool $enabled,
        public ?string $meter,
        public ?int $limit,
        public ?int $used,
        public ?int $remaining,
        public ?string $overage,
        public ?int $thresholdBps,
        public ?string $windowStart,
        public ?string $windowEnd,
    ) {}
}
