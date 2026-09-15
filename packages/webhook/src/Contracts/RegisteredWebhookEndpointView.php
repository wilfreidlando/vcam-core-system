<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

/**
 * `secret` is populated only here, at registration — never again by
 * `ListWebhookEndpoints` (ADR-0040 §5).
 */
final readonly class RegisteredWebhookEndpointView
{
    /**
     * @param list<string> $events
     */
    public function __construct(
        public string $id,
        public string $url,
        public array $events,
        public string $secret,
    ) {}
}
