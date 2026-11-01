<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * `projectId` is the Project whose page asks for the notification. The
 * check lives in the port, not in the controller (ADR-0069 §2): an
 * account that may see both projects must not read B's notification
 * under A's page — the answer is "not found", never another tenant's
 * content.
 */
final readonly class GetNotificationDetailsCommand
{
    public function __construct(
        public string $notificationId,
        public string $projectId,
    ) {}
}
