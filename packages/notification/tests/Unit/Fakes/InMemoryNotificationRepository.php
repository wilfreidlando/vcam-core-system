<?php

declare(strict_types=1);

namespace Core\Notification\Tests\Unit\Fakes;

use Core\Notification\Domain\Exception\NotificationAlreadyExists;
use Core\Notification\Domain\Model\Notification;
use Core\Notification\Domain\Port\NotificationRepository;

final class InMemoryNotificationRepository implements NotificationRepository
{
    /** @var array<string, Notification> */
    private array $notifications = [];

    public function create(Notification $notification): void
    {
        foreach ($this->notifications as $existing) {
            if ($existing->projectId === $notification->projectId
                && $existing->idempotencyKey === $notification->idempotencyKey
            ) {
                throw new NotificationAlreadyExists;
            }
        }

        $this->notifications[$notification->id] = $notification;
    }

    public function save(Notification $notification): void
    {
        $this->notifications[$notification->id] = $notification;
    }

    public function find(string $id): ?Notification
    {
        return $this->notifications[$id] ?? null;
    }

    public function findByIdempotencyKey(string $projectId, string $idempotencyKey): ?Notification
    {
        foreach ($this->notifications as $notification) {
            if ($notification->projectId === $projectId && $notification->idempotencyKey === $idempotencyKey) {
                return $notification;
            }
        }

        return null;
    }

    public function findByProviderMessage(string $providerCode, string $providerMessageId): ?Notification
    {
        foreach ($this->notifications as $notification) {
            if ($notification->providerCode === $providerCode && $notification->providerMessageId === $providerMessageId) {
                return $notification;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->notifications);
    }
}
