<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

interface SendFileNotification
{
    public function send(SendFileNotificationCommand $command): NotificationView;
}
