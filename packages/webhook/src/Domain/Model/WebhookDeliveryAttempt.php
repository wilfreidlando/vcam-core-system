<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Model;

use DateTimeImmutable;

/**
 * One HTTP attempt of a delivery (ADR-0059). `outcome` is what the Core
 * decided after it: `delivered`, `retry_scheduled`, `dead`.
 */
final readonly class WebhookDeliveryAttempt
{
    /**
     * @param array<string, string> $requestHeaders signature masked
     */
    public function __construct(
        public string $id,
        public string $deliveryId,
        public int $attempt,
        public DateTimeImmutable $attemptedAt,
        public string $url,
        public array $requestHeaders,
        public string $requestBody,
        public ?int $responseStatus,
        public ?string $responseBody,
        public ?int $durationMs,
        public ?string $error,
        public string $outcome,
        public ?string $correlationId = null,
        public ?string $traceId = null,
    ) {}
}
