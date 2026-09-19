<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * Single-project lookup by id, independent of its organization (review
 * finding, Lot 6 follow-up — the admin console's project detail page
 * used to have no way to resolve a project without already knowing its
 * organization, so it was a permanent stub always returning 404).
 */
interface GetProject
{
    /**
     * @throws RuntimeException if the project does not exist. The
     *                          concrete exception type lives in the
     *                          Domain layer; Contracts must not
     *                          reference it (G3c).
     */
    public function get(GetProjectCommand $command): ProjectView;
}
