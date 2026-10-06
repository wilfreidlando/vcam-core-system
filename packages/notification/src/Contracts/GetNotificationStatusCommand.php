<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Support\Environment;

final readonly class GetNotificationStatusCommand
{
    public function __construct(
        public string $notificationId,
        public string $requestingProjectId,
        // Null = no environment restriction (operator console).
        public ?Environment $requestingEnvironment = null,
    ) {}
}
