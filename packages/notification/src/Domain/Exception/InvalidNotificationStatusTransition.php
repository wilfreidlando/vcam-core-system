<?php

declare(strict_types=1);

namespace Core\Notification\Domain\Exception;

use Core\Notification\NotificationStatus;
use RuntimeException;

final class InvalidNotificationStatusTransition extends RuntimeException
{
    public static function from(NotificationStatus $current, NotificationStatus $next): self
    {
        return new self(sprintf(
            'Cannot transition a notification from "%s" to "%s".',
            $current->value,
            $next->value,
        ));
    }
}
