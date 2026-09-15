<?php

declare(strict_types=1);

namespace Core\Notification\Domain\Exception;

use RuntimeException;

final class NotificationNotFound extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Notification "%s" was not found.', $id));
    }
}
