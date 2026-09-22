<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

final readonly class UpdateWebhookEndpointCommand
{
    /**
     * @param list<string> $events
     */
    public function __construct(
        public string $projectId,
        public string $endpointId,
        public string $url,
        public array $events,
    ) {}
}
