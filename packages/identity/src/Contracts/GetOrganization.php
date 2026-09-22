<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

interface GetOrganization
{
    /**
     * @throws RuntimeException if the organization does not exist.
     */
    public function get(GetOrganizationCommand $command): OrganizationView;
}
