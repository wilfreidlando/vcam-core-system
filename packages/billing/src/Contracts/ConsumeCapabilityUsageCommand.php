<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0074 §5 — usage produced by the Core itself (a notification sent),
 * named by the **capability** that produced it, never by a meter.
 *
 * `$compensation` marks the unit given back when the effect failed
 * (`billing_settings.refund_failed_usage`).
 */
final readonly class ConsumeCapabilityUsageCommand
{
    public function __construct(
        public string $projectId,
        public ?string $customerRef,
        public string $capability,
        public int $quantity,
        public string $idempotencyKey,
        public string $source,
        public bool $compensation = false,
        public ?string $correlationId = null,
    ) {}
}
