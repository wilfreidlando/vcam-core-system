<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

use DateTimeImmutable;

final readonly class DeadWebhookDeliveryView
{
    public function __construct(
        public string $id,
        public string $endpointId,
        public string $projectId,
        public string $eventType,
        public int $attempts,
        public ?string $lastError,
        public ?DateTimeImmutable $lastAttemptAt,
    ) {}
}
