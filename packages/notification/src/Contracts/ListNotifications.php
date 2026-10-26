<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Support\Access\AccessScope;

/**
 * The operator console's project-scoped notification listing (ADR-0045).
 * Reuses `NotificationView` as-is — the same read model
 * `GetNotificationStatus` already returns for a single notification.
 */
interface ListNotifications
{
    /**
     * The scope is required (ADR-0069 §2): a listing never crosses the
     * projects the console account may see.
     */
    public function list(ListNotificationsCommand $command, AccessScope $scope): NotificationPage;
}
