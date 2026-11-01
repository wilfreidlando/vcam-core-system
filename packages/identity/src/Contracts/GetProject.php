<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use Core\Support\Access\AccessScope;
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
     * The scope is a **required** parameter (ADR-0069 §2): a console
     * account that does not see the project gets the same "not found" as
     * an account that does not know it — 404, never 403 (S-04).
     *
     * @throws RuntimeException if the project does not exist **or is out of
     *                          the caller's scope**. The concrete
     *                          exception type lives in the Domain layer;
     *                          Contracts must not reference it (G3c).
     */
    public function get(GetProjectCommand $command, AccessScope $scope): ProjectView;
}
