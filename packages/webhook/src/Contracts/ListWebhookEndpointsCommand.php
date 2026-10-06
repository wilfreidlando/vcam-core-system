<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

use Core\Support\Environment;

final readonly class ListWebhookEndpointsCommand
{
    public function __construct(
        public string $projectId,
        // Null = every environment (operator console).
        public ?Environment $environment = null,
    ) {}
}
