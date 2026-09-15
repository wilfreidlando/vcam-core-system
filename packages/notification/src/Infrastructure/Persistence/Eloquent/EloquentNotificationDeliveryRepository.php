<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Persistence\Eloquent;

use Core\Notification\Domain\Port\NotificationDeliveryRepository;
use Core\Notification\NotificationStatus;
use Illuminate\Support\Str;

final class EloquentNotificationDeliveryRepository implements NotificationDeliveryRepository
{
    public function record(
        string $notificationId,
        string $providerCode,
        ?string $providerMessageId,
        NotificationStatus $canonicalStatus,
        int $attempt,
    ): void {
        NotificationDeliveryRecord::query()->create([
            'id' => (string) Str::uuid(),
            'notification_id' => $notificationId,
            'provider_code' => $providerCode,
            'provider_message_id' => $providerMessageId,
            'canonical_status' => $canonicalStatus->value,
            'attempt' => $attempt,
        ]);
    }
}
