<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Unit\Fakes;

use Core\Identity\Domain\Model\OrganizationMembership;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;

final class InMemoryOrganizationMembershipRepository implements OrganizationMembershipRepository
{
    /** Number of times the owner rows were locked — lets a test prove the lock is taken. */
    public int $ownerLocks = 0;

    /** @var array<string, OrganizationMembership> */
    private array $memberships = [];

    public function save(OrganizationMembership $membership): void
    {
        $this->memberships[$membership->id] = $membership;
    }

    public function find(string $id): ?OrganizationMembership
    {
        return $this->memberships[$id] ?? null;
    }

    public function findActiveByOperator(string $operatorId): array
    {
        return array_values(array_filter(
            $this->memberships,
            static fn (OrganizationMembership $m): bool => $m->operatorId === $operatorId && ! $m->isRevoked(),
        ));
    }

    public function findActive(string $organizationId, string $operatorId): ?OrganizationMembership
    {
        foreach ($this->memberships as $membership) {
            if ($membership->organizationId === $organizationId && $membership->operatorId === $operatorId && ! $membership->isRevoked()) {
                return $membership;
            }
        }

        return null;
    }

    public function findActiveByOrganization(string $organizationId): array
    {
        return array_values(array_filter(
            $this->memberships,
            static fn (OrganizationMembership $m): bool => $m->organizationId === $organizationId && ! $m->isRevoked(),
        ));
    }

    public function lockActiveOwners(string $organizationId): int
    {
        $this->ownerLocks++;

        return count(array_filter(
            $this->findActiveByOrganization($organizationId),
            static fn (OrganizationMembership $m): bool => $m->role === OrganizationRole::Owner,
        ));
    }

    public function findActiveOperatorIdsByOrganizations(array $organizationIds): array
    {
        $operatorIds = [];

        foreach ($this->memberships as $membership) {
            if ($membership->isRevoked()) {
                continue;
            }

            if (! in_array($membership->organizationId, $organizationIds, true)) {
                continue;
            }

            $operatorIds[] = $membership->operatorId;
        }

        return array_values(array_unique($operatorIds));
    }
}
