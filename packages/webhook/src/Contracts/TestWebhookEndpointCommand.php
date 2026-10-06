<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

use Core\Support\Environment;

final readonly class TestWebhookEndpointCommand
{
    public function __construct(
        public string $projectId,
        public string $endpointId,
        // When given, the endpoint must belong to this environment.
        public ?Environment $environment = null,
    ) {}
}
