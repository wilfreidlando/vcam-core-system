<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * One counter of the console's usage page: who, which meter, how much of
 * how much, until when.
 */
final readonly class UsageCounterRow
{
    public function __construct(
        public string $customerRef,
        public string $meter,
        public int $used,
        public ?int $limit,
        public ?string $windowEnd,
        public int $percent,
        public string $updatedAt,
    ) {}

    public function isOver(): bool
    {
        return $this->limit !== null && $this->used >= $this->limit;
    }
}
