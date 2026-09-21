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

    public function findAll(int $page, int $perPage, ?string $search = null): array
    {
        $all = array_values($this->organizations);

        if ($search !== null && $search !== '') {
            $all = array_values(array_filter(
                $all,
                static fn (Organization $organization): bool => stripos($organization->name(), $search) !== false,
            ));
        }

        $total = count($all);
        $offset = ($page - 1) * $perPage;

        return ['items' => array_slice($all, $offset, $perPage), 'total' => $total];
    }
}
