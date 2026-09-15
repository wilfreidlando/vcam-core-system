<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use RuntimeException;

interface SendNotification
{
    /**
     * @throws RuntimeException when no template, or no provider chain, is configured
     */
    public function send(SendNotificationCommand $command): NotificationView;
}
