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
        /**
         * ADR-0082 §5 — while a secret rotation's overlap lasts, deliveries
         * also carry `X-Signature-Previous`; null otherwise. Serialized as
         * is in the public API, hence the snake_case name.
         */
        public ?string $previous_secret_expires_at = null,
    ) {}
}
