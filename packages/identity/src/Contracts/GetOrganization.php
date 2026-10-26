<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use Core\Support\Access\AccessScope;
use RuntimeException;

interface GetOrganization
{
    /**
     * @throws RuntimeException if the organization does not exist **or is
     *                          out of the caller's scope** (ADR-0069 §2).
     */
    public function get(GetOrganizationCommand $command, AccessScope $scope): OrganizationView;
}
