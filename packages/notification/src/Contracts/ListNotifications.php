<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * The operator console's project-scoped notification listing (ADR-0045).
 * Reuses `NotificationView` as-is — the same read model
 * `GetNotificationStatus` already returns for a single notification.
 */
interface ListNotifications
{
    public function list(ListNotificationsCommand $command): NotificationPage;
}
