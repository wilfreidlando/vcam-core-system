<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0118 §4 — the console's door to the declarations of a project:
 * « préviens-moi de tel événement, sur tel canal ».
 *
 * The scope is required (ADR-0069 §2, G16): a project outside it is not
 * found. The module stores the declarations without knowing what they mean:
 * the events and the channels are the project's own words.
 */
interface ManageSystemNotificationSettings
{
    /**
     * The declarations of a project — an empty list when it declared none.
     */
    public function get(string $projectId, AccessScope $scope): ?SystemNotificationSettingsView;

    /**
     * Replaces the declarations of a project with the given ones.
     */
    public function save(SaveSystemNotificationSettingsCommand $command, AccessScope $scope): ?SystemNotificationSettingsView;
}
