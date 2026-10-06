<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

final readonly class WebhookEndpointView
{
    /**
     * @param list<string> $events
     */
    public function __construct(
        public string $id,
        public string $url,
        public array $events,
        public bool $active,
        public string $environment = 'live',
    ) {}
}
