<?php

declare(strict_types=1);

namespace Core\Notification\Domain\Exception;

use RuntimeException;

final class NotificationTemplateNotFound extends RuntimeException
{
    public static function forKey(string $projectId, string $key, string $channel): self
    {
        return new self(sprintf(
            'No approved template "%s" for channel "%s" (project %s).',
            $key,
            $channel,
            $projectId,
        ));
    }
}
