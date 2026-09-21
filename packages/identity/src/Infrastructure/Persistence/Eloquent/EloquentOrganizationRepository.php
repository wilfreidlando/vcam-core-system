<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Persistence\Eloquent;

use Core\Identity\Domain\Model\Organization;
use Core\Identity\Domain\Port\OrganizationRepository;

final class EloquentOrganizationRepository implements OrganizationRepository
{
    public function save(Organization $organization): void
    {
        OrganizationRecord::query()->updateOrCreate(
            ['id' => $organization->id],
            ['name' => $organization->name(), 'status' => $organization->status()],
        );
    }

    public function find(string $id): ?Organization
    {
        $record = OrganizationRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findAll(int $page, int $perPage, ?string $search = null): array
    {
        // `orderBy`/`skip`/`take`/`count` are forwarded by Eloquent\Builder
        // to the underlying query builder rather than declared on it
        // directly; going through `toBase()` first avoids a Larastan false
        // positive ("dynamic call to static method") on those forwarded
        // calls, and `OrganizationRecord::hydrate()` restores the casts
        // after. Tiebreaker on the primary key: two rows can share a
        // created_at, and without it a row can duplicate or vanish across
        // pages.
        $base = OrganizationRecord::query()->toBase();

        if ($search !== null && $search !== '') {
            $base->where('name', 'ilike', '%'.$search.'%');
        }

        $total = (clone $base)->count();

        $base->orderBy('created_at', 'desc');
        $base->orderBy('id', 'desc');
        $base->skip(($page - 1) * $perPage);
        $base->take($perPage);

        $rows = $base->get()->map(static fn (object $row): array => (array) $row)->all();

        $records = OrganizationRecord::hydrate($rows);

        $organizations = [];
        foreach ($records as $record) {
            $organizations[] = $this->toDomain($record);
        }

        return ['items' => $organizations, 'total' => $total];
    }

    private function toDomain(OrganizationRecord $record): Organization
    {
        return new Organization($record->id, $record->name, $record->status);
    }
}
