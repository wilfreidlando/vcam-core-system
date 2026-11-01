<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Port;

use Core\Webhook\Domain\Exception\WebhookDeliveryAlreadyExists;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use DateTimeImmutable;

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
     * `status` narrows to one lifecycle state; `null` lists every status —
     * the full delivery history a diagnosing operator needs, not just the
     * dead queue (`ListWebhookDeliveries`, review finding).
     *
     * `projectIds` is the account's scope (ADR-0069 §2): when given, only
     * the deliveries of those projects are returned — the restriction is
     * applied by the query, never by the caller. `null` means "no
     * restriction" (a platform scope); an empty list means "nothing is
     * visible".
     *
     * @param list<string>|null $projectIds
     * @return array{items: list<WebhookDelivery>, total: int}
     */
    public function findByStatusPaginated(?WebhookDeliveryStatus $status, ?string $projectId, int $page, int $perPage, ?array $projectIds = null): array;

    /**
     * `pending` deliveries whose due time (`next_retry_at`, or creation for
     * a never-attempted one) is older than `$overdueBefore` — rows whose
     * queued job was lost (audit 2026-10-03 §4.3).
     *
     * @return list<WebhookDelivery>
     */
    public function findStalledPending(DateTimeImmutable $overdueBefore, int $limit): array;

    /**
     * Every delivery (fan-out and replays) of these outbox events, oldest first.
     *
     * `projectIds` is the account's scope (ADR-0069 §2): when given, only
     * the deliveries of those projects are returned. `null` means "no
     * restriction"; an empty list means "nothing is visible".
     *
     * @param list<string> $eventIds
     * @param list<string>|null $projectIds
     * @return list<WebhookDelivery>
     */
    public function findByEventIds(array $eventIds, ?array $projectIds = null): array;
}
