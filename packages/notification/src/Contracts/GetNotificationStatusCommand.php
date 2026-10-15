<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

final readonly class GetNotificationStatusCommand
{
    public function __construct(
        public string $notificationId,
        public string $requestingProjectId,
    ) {}
}
