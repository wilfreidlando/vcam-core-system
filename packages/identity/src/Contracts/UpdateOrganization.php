<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

interface UpdateOrganization
{
    /**
     * @throws RuntimeException if `$command->organizationId` doesn't
     *                          resolve to an existing Organization.
     */
    public function update(UpdateOrganizationCommand $command, string $actor, ?string $correlationId): void;
}
