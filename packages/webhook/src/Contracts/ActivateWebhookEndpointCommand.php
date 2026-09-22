<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

final readonly class ActivateWebhookEndpointCommand
{
    public function __construct(
        public string $projectId,
        public string $endpointId,
    ) {}
}
