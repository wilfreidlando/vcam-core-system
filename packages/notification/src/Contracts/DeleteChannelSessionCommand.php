<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

final readonly class DeleteChannelSessionCommand
{
    public function __construct(
        public ?string $projectId,
        public NotificationChannel $channel,
        public string $sessionRef,
    ) {}
}
