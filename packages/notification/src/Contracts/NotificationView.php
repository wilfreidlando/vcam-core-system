<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Notification\NotificationStatus;

/**
 * The read model returned to a caller — never
 * `Core\Notification\Domain\Model\Notification`, which stays internal
 * (G3c). Carries only canonical vocabulary: no raw provider status.
 */
final readonly class NotificationView
{
    public function __construct(
        public string $id,
        public string $channel,
        public NotificationStatus $status,
        public string $templateKey,
        public ?string $errorMessage,
    ) {}
}
