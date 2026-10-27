<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0072 §7 — `POST /v1/subscriptions/{id}/cancel`.
 *
 * `atPeriodEnd` cancels at the end of the current period (the default —
 * the customer keeps its rights until then); `false` cancels immediately
 * and what is left of the period is lost.
 */
final readonly class CancelSubscriptionCommand
{
    /**
     * @param string $actor "operator:<uuid>", "project:<uuid>" or "service:<name>" — who asked for the write
     */
    public function __construct(
        public string $projectId,
        public string $subscriptionId,
        public bool $atPeriodEnd = true,
        public ?string $reason = null,
        public string $actor = '',
        public ?string $correlationId = null,
    ) {}
}
