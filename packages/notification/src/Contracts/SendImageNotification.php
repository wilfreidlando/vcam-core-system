<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

interface SendImageNotification
{
    public function send(SendImageNotificationCommand $command): NotificationView;
}
