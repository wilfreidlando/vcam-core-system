<?php

declare(strict_types=1);

namespace Core\Notification\Tests\Unit\Fakes;

use Core\Notification\Domain\Exception\NotificationAlreadyExists;
use Core\Notification\Domain\Model\Notification;
use Core\Notification\Domain\Port\NotificationRepository;
use Core\Notification\NotificationStatus;

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

    public function findByProject(string $projectId, ?NotificationStatus $status, int $page, int $perPage): array
    {
        $matching = array_values(array_filter(
            $this->notifications,
            static fn (Notification $notification): bool => $notification->projectId === $projectId
                && ($status === null || $notification->status === $status),
        ));

        $total = count($matching);
        $offset = ($page - 1) * $perPage;

        return ['items' => array_slice($matching, $offset, $perPage), 'total' => $total];
    }
}
