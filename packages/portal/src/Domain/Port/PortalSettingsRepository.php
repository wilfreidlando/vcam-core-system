<?php

declare(strict_types=1);

namespace Core\Portal\Domain\Port;

use Core\Portal\Domain\Model\PortalSettings;

interface PortalSettingsRepository
{
    /**
     * The settings a project really chose, or null when it never chose any.
     *
     * The repository says what exists; the defaults are the caller's
     * business (`PortalSettings::defaultsFor`). The distinction is what
     * keeps the public stylesheet of the portal from being an oracle: every
     * project without settings answers the neutral palette, byte for byte
     * the same as a project that does not exist (audit #11, garde-fou G-9).
     */
    public function find(string $projectId): ?PortalSettings;

    public function save(PortalSettings $settings): void;
}
