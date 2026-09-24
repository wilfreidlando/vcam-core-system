<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

final readonly class ListChannelSessionsCommand
{
    public function __construct(
        public ?string $projectId,
        public NotificationChannel $channel,
    ) {}
}
