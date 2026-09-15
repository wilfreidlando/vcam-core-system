<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Port;

use Core\Webhook\Domain\Exception\WebhookDeliveryAlreadyExists;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;

interface WebhookDeliveryRepository
{
    /**
     * @throws WebhookDeliveryAlreadyExists when `(endpointId, eventId)` already has a delivery
     *                                      (ADR-0040 §4 — the fan-out is at-least-once and must stay idempotent)
     */
    public function create(WebhookDelivery $delivery): void;

    public function save(WebhookDelivery $delivery): void;

    public function find(string $id): ?WebhookDelivery;

    /**
     * Newest first. `projectId` narrows to one Project's deliveries;
     * `null` lists across every Project (ADR-0044 — the operator console).
     *
     * @return array{items: list<WebhookDelivery>, total: int}
     */
    public function findByStatusPaginated(WebhookDeliveryStatus $status, ?string $projectId, int $page, int $perPage): array;
}
