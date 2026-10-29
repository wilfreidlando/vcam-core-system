<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * Take back a cancellation requested for the end of the period.
 */
final readonly class KeepSubscriptionRenewingCommand
{
    public function __construct(
        public string $projectId,
        public string $subscriptionId,
        public string $actor = '',
        public ?string $correlationId = null,
    ) {}
}
