<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Persistence\Eloquent;

use Core\Identity\Domain\Model\OrganizationMembership;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;

final class EloquentOrganizationMembershipRepository implements OrganizationMembershipRepository
{
    public function save(OrganizationMembership $membership): void
    {
        OrganizationMembershipRecord::query()->updateOrCreate(
            ['id' => $membership->id],
            [
                'organization_id' => $membership->organizationId,
                'operator_id' => $membership->operatorId,
                'role' => $membership->role->value,
                'created_at' => $membership->createdAt,
                'revoked_at' => $membership->revokedAt(),
            ],
        );
    }

    public function find(string $id): ?OrganizationMembership
    {
        $record = OrganizationMembershipRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findActiveByOperator(string $operatorId): array
    {
        return $this->hydrate(
            OrganizationMembershipRecord::query()
                ->where('operator_id', $operatorId)
                ->where('revoked_at', null)
                ->get()
                ->all()
        );
    }

    public function findActive(string $organizationId, string $operatorId): ?OrganizationMembership
    {
        $record = OrganizationMembershipRecord::query()
            ->where('organization_id', $organizationId)
            ->where('operator_id', $operatorId)
            ->where('revoked_at', null)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findActiveByOrganization(string $organizationId): array
    {
        return $this->hydrate(
            OrganizationMembershipRecord::query()
                ->where('organization_id', $organizationId)
                ->where('revoked_at', null)
                ->get()
                ->all()
        );
    }

    public function lockActiveOwners(string $organizationId): int
    {
        // `FOR UPDATE` cannot carry an aggregate, so the rows are locked
        // and counted here. Ordered by id: every caller locks the owner
        // rows of an organization in the same order, so two concurrent
        // revocations wait on each other instead of deadlocking.
        // In READ COMMITTED, a waiting `FOR UPDATE` re-checks its WHERE
        // clause once the lock is granted: an owner revoked or demoted by
        // the transaction it waited for is no longer counted.
        $lockedIds = OrganizationMembershipRecord::query()
            ->toBase()
            ->where('organization_id', $organizationId)
            ->where('role', OrganizationRole::Owner->value)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all();

        return count($lockedIds);
    }

    public function lockActiveWithRole(string $organizationId, OrganizationRole $role): int
    {
        // Same discipline as `lockActiveOwners()`, for any role: the rows
        // are locked in a stable order (so two callers never deadlock) and
        // counted in PHP (`FOR UPDATE` cannot carry an aggregate).
        $lockedIds = OrganizationMembershipRecord::query()
            ->toBase()
            ->where('organization_id', $organizationId)
            ->where('role', $role->value)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all();

        return count($lockedIds);
    }

    /**
     * The ids of the accounts that are active members of at least one of
     * the given organizations — the console's operator listing scope
     * (ADR-0069 §2).
     *
     * @param list<string> $organizationIds
     * @return list<string>
     */
    public function findActiveOperatorIdsByOrganizations(array $organizationIds): array
    {
        // Nothing to look up: an account that belongs to no organization
        // sees no operator at all.
        if ($organizationIds === []) {
            return [];
        }

        // `toBase()` first: `whereIn`/`pluck` are forwarded by
        // Eloquent\Builder to the underlying query builder rather than
        // declared on it directly, and going through `toBase()` avoids a
        // Larastan false positive ("dynamic call to static method") on
        // those forwarded calls — same workaround as the other
        // repositories of the Core.
        $ids = OrganizationMembershipRecord::query()
            ->toBase()
            ->whereIn('organization_id', $organizationIds)
            ->whereNull('revoked_at')
            ->distinct()
            ->pluck('operator_id')
            ->all();

        $operatorIds = [];
        foreach ($ids as $id) {
            if (is_string($id)) {
                $operatorIds[] = $id;
            }
        }

        return array_values(array_unique($operatorIds));
    }

    /**
     * @param array<int, OrganizationMembershipRecord> $records
     * @return list<OrganizationMembership>
     */
    private function hydrate(array $records): array
    {
        $memberships = [];
        foreach ($records as $record) {
            $memberships[] = $this->toDomain($record);
        }

        return $memberships;
    }

    private function toDomain(OrganizationMembershipRecord $record): OrganizationMembership
    {
        return new OrganizationMembership(
            $record->id,
            $record->organization_id,
            $record->operator_id,
            OrganizationRole::from($record->role),
            $record->created_at,
            $record->revoked_at,
        );
    }
}
