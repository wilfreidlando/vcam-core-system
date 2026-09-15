<?php

declare(strict_types=1);

namespace Core\Notification\Domain\Port;

use Core\Notification\Domain\Exception\NotificationAlreadyExists;
use Core\Notification\Domain\Model\Notification;
use Core\Notification\NotificationStatus;

interface NotificationRepository
{
    /**
     * @throws NotificationAlreadyExists when `(project_id, idempotency_key)` already exists
     */
    public function create(Notification $notification): void;

    public function save(Notification $notification): void;

    public function find(string $id): ?Notification;

    public function findByIdempotencyKey(string $projectId, string $idempotencyKey): ?Notification;

    public function findByProviderMessage(string $providerCode, string $providerMessageId): ?Notification;

    /**
     * Newest first, optionally narrowed to one canonical `status`
     * (ADR-0045 — the operator console's project-scoped listing).
     *
     * @return array{items: list<Notification>, total: int}
     */
    public function findByProject(string $projectId, ?NotificationStatus $status, int $page, int $perPage): array;
}
