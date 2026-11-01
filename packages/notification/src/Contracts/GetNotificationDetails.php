<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Support\Access\AccessScope;

interface GetNotificationDetails
{
    /**
     * The scope is required (ADR-0069 §2): a notification outside the
     * caller's scope is "not found", never "forbidden" (S-04).
     */
    public function get(GetNotificationDetailsCommand $command, AccessScope $scope): ?NotificationDetailView;
}
