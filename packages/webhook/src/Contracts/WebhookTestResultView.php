<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

final readonly class WebhookTestResultView
{
    public function __construct(
        public bool $delivered,
        public string $eventId,
        public ?int $responseStatus,
        public ?string $responseBody,
        public ?string $error,
        public ?int $durationMs,
    ) {}
}
