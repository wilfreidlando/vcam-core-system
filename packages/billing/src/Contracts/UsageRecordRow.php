<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * One line of the usage journal (the truth the counters project).
 */
final readonly class UsageRecordRow
{
    public function __construct(
        public string $id,
        public ?string $customerRef,
        public string $meter,
        public int $quantity,
        public string $source,
        public string $idempotencyKey,
        public string $occurredAt,
        public string $recordedAt,
    ) {}
}
