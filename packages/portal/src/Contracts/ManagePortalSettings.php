<?php

declare(strict_types=1);

namespace Core\Portal\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0075 §4 — the console's door to the settings of a portal.
 *
 * The scope is a **required** parameter (ADR-0069 §2): a project outside
 * it is not found. An invalid value raises
 * `Core\Portal\Domain\Exception\InvalidPortalSettings`.
 */
interface ManagePortalSettings
{
    public function get(string $projectId, AccessScope $scope): ?PortalSettingsView;

    public function save(SavePortalSettingsCommand $command, AccessScope $scope): ?PortalSettingsView;
}
