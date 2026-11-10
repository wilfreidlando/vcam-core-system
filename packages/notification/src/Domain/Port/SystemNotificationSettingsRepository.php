<?php

declare(strict_types=1);

namespace Core\Notification\Domain\Port;

use Core\Notification\Domain\Model\SystemNotificationPreference;

interface SystemNotificationSettingsRepository
{
    /**
     * The declarations of a project — an empty list when it declared none
     * (ADR-0118 §4: the opt-in).
     *
     * @return list<SystemNotificationPreference>
     */
    public function listFor(string $projectId): array;

    /**
     * Replaces the declarations of a project with the given ones.
     *
     * @param list<SystemNotificationPreference> $preferences
     */
    public function replace(string $projectId, array $preferences): void;
}
