<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0072 §7 — the contract to read, by its id.
 */
final readonly class GetSubscriptionCommand
{
    public function __construct(
        public string $projectId,
        public string $subscriptionId,
    ) {}
}
