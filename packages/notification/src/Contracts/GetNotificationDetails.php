<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

interface GetNotificationDetails
{
    public function get(string $notificationId): ?NotificationDetailView;
}
