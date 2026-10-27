<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0072 §6 — the operator's gesture: the contract becomes `active`
 * again and the current period is extended by the pause duration.
 */
final readonly class ResumeSubscriptionCommand
{
    /**
     * @param string $actor "operator:<uuid>", "project:<uuid>" or "service:<name>" — who asked for the write
     */
    public function __construct(
        public string $projectId,
        public string $subscriptionId,
        public string $actor = '',
        public ?string $correlationId = null,
    ) {}
}
