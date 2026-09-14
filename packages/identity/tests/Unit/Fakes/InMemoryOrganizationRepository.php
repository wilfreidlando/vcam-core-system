<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Unit\Fakes;

use Core\Identity\Domain\Model\Organization;
use Core\Identity\Domain\Port\OrganizationRepository;

final class InMemoryOrganizationRepository implements OrganizationRepository
{
    /** @var array<string, Organization> */
    private array $organizations = [];

    public function save(Organization $organization): void
    {
        $this->organizations[$organization->id] = $organization;
    }

    public function find(string $id): ?Organization
    {
        return $this->organizations[$id] ?? null;
    }
}
