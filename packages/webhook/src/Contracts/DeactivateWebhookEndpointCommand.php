<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

final readonly class DeactivateWebhookEndpointCommand
{
    public function __construct(
        public string $projectId,
        public string $endpointId,
    ) {}
}
