<?php

declare(strict_types=1);

namespace Core\Notification\Domain\Port;

use Core\Notification\Domain\Model\NotificationTemplate;

interface NotificationTemplateRepository
{
    /**
     * The highest-versioned template for `(project, key, channel)`,
     * whatever its `status` — the caller decides whether `isSendable()`
     * matters, so the port stays a plain lookup, not a business rule.
     */
    public function findLatest(string $projectId, string $key, string $channel): ?NotificationTemplate;
}
