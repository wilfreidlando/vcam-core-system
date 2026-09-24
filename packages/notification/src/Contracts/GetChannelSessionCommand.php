<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * `projectId === null` addresses a global (platform) session — only the
 * admin console does that; the client-facing endpoints always pass the
 * calling Project.
 */
final readonly class GetChannelSessionCommand
{
    public function __construct(
        public ?string $projectId,
        public NotificationChannel $channel,
        public string $sessionRef,
    ) {}
}
