<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

use DateTimeImmutable;

final readonly class DeadWebhookDeliveryView
{
    /**
     * @param array<string, mixed> $payload see `WebhookDeliveryView`'s identical param — the
     *                                      same reconstruction reasoning applies here.
     */
    public function __construct(
        public string $id,
        public string $endpointId,
        public string $projectId,
        public string $eventId,
        public string $eventType,
        public array $payload,
        public int $attempts,
        public ?string $lastError,
        public ?int $lastResponseStatus,
        public ?string $lastResponseBody,
        public ?DateTimeImmutable $lastAttemptAt,
    ) {}
}
