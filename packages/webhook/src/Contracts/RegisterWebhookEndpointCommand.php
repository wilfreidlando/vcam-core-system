<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

use Core\Support\Environment;

final readonly class RegisterWebhookEndpointCommand
{
    /**
     * @param list<string> $events subscribed event types, or `['*']` for every type
     */
    public function __construct(
        public string $projectId,
        public string $url,
        public array $events,
        public Environment $environment = Environment::Live,
    ) {}
}
