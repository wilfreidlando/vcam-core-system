<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

final readonly class ListWebhookEndpointsCommand
{
    public function __construct(
        public string $projectId,
    ) {}
}
