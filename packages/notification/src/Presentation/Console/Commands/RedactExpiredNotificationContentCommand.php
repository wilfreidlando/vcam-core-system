<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Console\Commands;

use Core\Notification\Domain\Port\NotificationRepository;
use Core\Support\Clock;
use Illuminate\Console\Command;

/**
 * ADR-0062 / decision D5 — the content of a notification and its variables
 * (the recipient's phone number among them) are kept
 * `notification.content_retention_days` (90 by default), then emptied.
 * Scheduled daily; the notification row itself is kept.
 */
final class RedactExpiredNotificationContentCommand extends Command
{
    protected $signature = 'notification:redact-expired';

    protected $description = 'Empty the content and variables of notifications older than the retention period.';

    public function handle(NotificationRepository $notifications, Clock $clock): int
    {
        $configured = config('notification.content_retention_days', 90);
        $days = is_numeric($configured) ? max(1, (int) $configured) : 90;

        $count = $notifications->redactContentCreatedBefore($clock->now()->modify("-{$days} days"));

        $this->info(sprintf('Redacted %d notification%s older than %d days.', $count, $count === 1 ? '' : 's', $days));

        return self::SUCCESS;
    }
}
