<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

interface SendTextNotification
{
    public function send(SendTextNotificationCommand $command): NotificationView;
}
