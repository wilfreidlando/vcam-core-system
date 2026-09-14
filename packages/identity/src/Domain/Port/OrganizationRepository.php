<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Port;

use Core\Identity\Domain\Model\Organization;

interface OrganizationRepository
{
    public function save(Organization $organization): void;

    public function find(string $id): ?Organization;
}
