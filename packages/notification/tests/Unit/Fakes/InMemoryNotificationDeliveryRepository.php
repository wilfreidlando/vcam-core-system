<?php

declare(strict_types=1);

namespace Core\Notification\Tests\Unit\Fakes;

use Core\Notification\Domain\Port\NotificationDeliveryRepository;
use Core\Notification\NotificationStatus;

final class InMemoryNotificationDeliveryRepository implements NotificationDeliveryRepository
{
    /**
     * @var list<array{notificationId: string, providerCode: string, providerMessageId: ?string, status: NotificationStatus, attempt: int}>
     */
    public array $records = [];

    public function record(
        string $notificationId,
        string $providerCode,
        ?string $providerMessageId,
        NotificationStatus $canonicalStatus,
        int $attempt,
    ): void {
        $this->records[] = [
            'notificationId' => $notificationId,
            'providerCode' => $providerCode,
            'providerMessageId' => $providerMessageId,
            'status' => $canonicalStatus,
            'attempt' => $attempt,
        ];
    }
}
