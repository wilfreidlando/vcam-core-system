<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0072 §5 — `POST /v1/subscriptions/{id}/change-plan`.
 *
 * `effective` is `period_end` (the default — the next period is created
 * with the new version) or `immediate` (the current period is closed now
 * and a new one starts).
 */
final readonly class ChangeSubscriptionPlanCommand
{
    /**
     * @param string $actor "operator:<uuid>", "project:<uuid>" or "service:<name>" — who asked for the write
     */
    public function __construct(
        public string $projectId,
        public string $subscriptionId,
        public string $planCode,
        public string $effective = 'period_end',
        public string $actor = '',
        public ?string $correlationId = null,
    ) {}
}
