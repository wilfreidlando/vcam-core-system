<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0074 §4 — one metered call.
 *
 * `$mode` is `enforce` (refuse beyond the limit) or `record` (a fact that
 * already happened). `$quantity` is negative to release a gauge or to
 * compensate a failed effect; zero is meaningless and refused upstream.
 */
final readonly class ConsumeUsageCommand
{
    public function __construct(
        public string $projectId,
        public ?string $customerRef,
        public string $meter,
        public int $quantity,
        public string $idempotencyKey,
        public string $source,
        public string $mode = 'enforce',
        public ?string $occurredAt = null,
        public ?string $correlationId = null,
    ) {}
}
