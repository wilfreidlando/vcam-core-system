<?php

declare(strict_types=1);

namespace Core\Notification\Domain\Model;

use Core\Notification\NotificationTemplateKind;
use Core\Notification\NotificationTemplateStatus;

/**
 * One versioned template (spec §6.7). Immutable — a new version is a new
 * row, never an in-place edit, so a notification already sent stays
 * reproducible from its recorded `templateKey`/version pair.
 */
final readonly class NotificationTemplate
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $key,
        public string $channel,
        public NotificationTemplateKind $kind,
        public NotificationTemplateStatus $status,
        public int $version,
        public array $body,
    ) {}

    public function isSendable(): bool
    {
        return $this->status === NotificationTemplateStatus::Approved;
    }
}
