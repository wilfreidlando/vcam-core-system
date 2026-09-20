<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

interface CreateOrganization
{
    public function create(CreateOrganizationCommand $command, string $actor, ?string $correlationId): OrganizationView;
}
