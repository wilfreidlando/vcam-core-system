<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use DateTimeImmutable;

/**
 * ADR-0074 §2 — everything the atomic admission needs, already resolved.
 *
 * The limit, the overage rule and the window come from the plan version
 * **at the time the window opens** (ADR-0074 §3); the store never reads
 * a plan — it only counts.
 */
final readonly class UsageAdmissionRequest
{
    public function __construct(
        public string $recordId,
        public string $projectId,
        public ?string $customerId,
        public string $meter,
        public int $quantity,
        public string $idempotencyKey,
        public string $source,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $now,
        public UsageMode $mode,
        public ?DateTimeImmutable $windowStart,
        public ?DateTimeImmutable $windowEnd,
        public ?int $limit,
        public FeatureOverage $overage,
        public ?int $thresholdBps,
    ) {}
}
