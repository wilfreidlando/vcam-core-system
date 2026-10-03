<?php

declare(strict_types=1);

namespace Core\Notification\Tests\Unit\Fakes;

use Core\Notification\Domain\Port\NotificationDeliveryRepository;
use Core\Notification\NotificationStatus;

final class InMemoryNotificationDeliveryRepository implements NotificationDeliveryRepository
{
    /**
     * @var list<array{notificationId: string, providerCode: string, providerMessageId: ?string, status: NotificationStatus, attempt: int, error: ?string}>
     */
    public array $records = [];

    public function record(
        string $notificationId,
        string $providerCode,
        ?string $providerMessageId,
        NotificationStatus $canonicalStatus,
        int $attempt,
        ?string $error = null,
    ): void {
        $this->records[] = [
            'notificationId' => $notificationId,
            'providerCode' => $providerCode,
            'providerMessageId' => $providerMessageId,
            'status' => $canonicalStatus,
            'attempt' => $attempt,
            'error' => $error,
        ];
    }

    public function forNotification(string $notificationId): array
    {
        $attempts = [];
        foreach ($this->records as $record) {
            if ($record['notificationId'] === $notificationId) {
                $attempts[] = [
                    'provider_code' => $record['providerCode'],
                    'provider_message_id' => $record['providerMessageId'],
                    'status' => $record['status']->value,
                    'attempt' => $record['attempt'],
                    'error' => $record['error'],
                    'created_at' => '2026-01-01T00:00:00+00:00',
                ];
            }
        }

        return $attempts;
    }
}
