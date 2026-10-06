<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

final readonly class WebhookDeliveryAttemptView
{
    /**
     * @param array<string, string> $requestHeaders signature masked
     * @param string $outcome `delivered`, `retry_scheduled` or `dead`
     */
    public function __construct(
        public int $attempt,
        public string $attemptedAt,
        public string $url,
        public array $requestHeaders,
        public string $requestBody,
        public ?int $responseStatus,
        public ?string $responseBody,
        public ?int $durationMs,
        public ?string $error,
        public string $outcome,
        public ?string $correlationId,
        public ?string $traceId,
    ) {}
}
