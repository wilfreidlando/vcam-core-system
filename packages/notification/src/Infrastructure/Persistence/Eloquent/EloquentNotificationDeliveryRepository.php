<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Persistence\Eloquent;

use Core\Notification\Domain\Port\NotificationDeliveryRepository;
use Core\Notification\NotificationStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentNotificationDeliveryRepository implements NotificationDeliveryRepository
{
    public function record(
        string $notificationId,
        string $providerCode,
        ?string $providerMessageId,
        NotificationStatus $canonicalStatus,
        int $attempt,
        ?string $error = null,
    ): void {
        NotificationDeliveryRecord::query()->create([
            'id' => (string) Str::uuid(),
            'notification_id' => $notificationId,
            'provider_code' => $providerCode,
            'provider_message_id' => $providerMessageId,
            'canonical_status' => $canonicalStatus->value,
            'attempt' => $attempt,
            'error' => $error,
        ]);
    }

    public function forNotification(string $notificationId): array
    {
        $rows = DB::table('notification_deliveries')
            ->where('notification_id', $notificationId)
            ->orderBy('created_at')
            ->orderBy('attempt')
            ->get();

        $attempts = [];
        foreach ($rows as $row) {
            /** @var array{provider_code: string, provider_message_id: string|null, canonical_status: string, attempt: int|string, error?: string|null, created_at: string} $data */
            $data = (array) $row;
            $attempts[] = [
                'provider_code' => $data['provider_code'],
                'provider_message_id' => $data['provider_message_id'],
                'status' => $data['canonical_status'],
                'attempt' => (int) $data['attempt'],
                'error' => $data['error'] ?? null,
                'created_at' => $data['created_at'],
            ];
        }

        return $attempts;
    }
}
