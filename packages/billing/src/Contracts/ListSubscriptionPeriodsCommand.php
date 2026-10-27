<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0072 §1 — the periods of one contract.
 */
final readonly class ListSubscriptionPeriodsCommand
{
    public function __construct(
        public string $projectId,
        public string $subscriptionId,
    ) {}
}
