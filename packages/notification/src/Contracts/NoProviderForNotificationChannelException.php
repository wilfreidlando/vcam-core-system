<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use RuntimeException;

/**
 * No provider chain (project override or platform default) is configured
 * for a channel — mirrors `Core\Payment\Contracts\NoProviderForChannelException`:
 * a configuration gap, not a customer-facing error.
 */
final class NoProviderForNotificationChannelException extends RuntimeException
{
    public static function forChannel(NotificationChannel $channel, string $projectId): self
    {
        return new self("No provider is configured for notification channel '{$channel}' (project {$projectId}).");
    }
}
