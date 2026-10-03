<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

/**
 * ADR-0059 — one delivery with its full attempt history, for the admin
 * console ("Parcours" of a payment, delivery detail page).
 */
final readonly class WebhookDeliveryDetailView
{
    /**
     * @param array<string, mixed> $payload
     * @param list<WebhookDeliveryAttemptView> $attempts oldest first
     */
    public function __construct(
        public string $id,
        public string $endpointId,
        public ?string $endpointUrl,
        public ?bool $endpointActive,
        public string $projectId,
        public string $eventId,
        public string $eventType,
        public array $payload,
        public string $status,
        public int $attemptCount,
        public ?string $nextRetryAt,
        public ?string $lastAttemptAt,
        public ?string $deliveredAt,
        public ?string $lastError,
        public ?string $replayedFromId,
        public array $attempts,
    ) {}
}
