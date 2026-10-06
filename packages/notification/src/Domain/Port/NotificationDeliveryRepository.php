<?php

declare(strict_types=1);

namespace Core\Notification\Domain\Port;

use Core\Notification\NotificationStatus;

/**
 * Append-only audit trail of every send attempt (spec §6.7's
 * `notification_deliveries`) — one row per provider tried, successful or
 * not, so a fallback cascade (ADR-0041 §3) stays fully auditable even
 * when every attempt fails. Deliberately minimal (no Domain entity of its
 * own, mirrors `Core\Audit`'s design): there is no business rule here
 * beyond "record what happened".
 */
interface NotificationDeliveryRepository
{
    public function record(
        string $notificationId,
        string $providerCode,
        ?string $providerMessageId,
        NotificationStatus $canonicalStatus,
        int $attempt,
        ?string $error = null,
    ): void;

    /**
     * ADR-0062 — every provider attempt of a notification, oldest first.
     *
     * @return list<array{provider_code: string, provider_message_id: string|null, status: string, attempt: int, error: string|null, created_at: string}>
     */
    public function forNotification(string $notificationId): array;
}
