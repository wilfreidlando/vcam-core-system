<?php

declare(strict_types=1);

namespace Core\Notification\Domain\Port;

use Core\Notification\Domain\Exception\NotificationAlreadyExists;
use Core\Notification\Domain\Model\Notification;

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
}
