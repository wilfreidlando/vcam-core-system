<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use RuntimeException;

interface GetNotificationStatus
{
    /**
     * @throws RuntimeException when the notification does not exist, or belongs to another project
     */
    public function get(GetNotificationStatusCommand $command): NotificationView;
}
