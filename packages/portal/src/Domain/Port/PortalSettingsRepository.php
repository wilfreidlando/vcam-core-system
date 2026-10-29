<?php

declare(strict_types=1);

namespace Core\Portal\Domain\Port;

use Core\Portal\Domain\Model\PortalSettings;

interface PortalSettingsRepository
{
    /**
     * The settings of a project, or the defaults when it never chose any.
     */
    public function find(string $projectId): PortalSettings;

    public function save(PortalSettings $settings): void;
}
