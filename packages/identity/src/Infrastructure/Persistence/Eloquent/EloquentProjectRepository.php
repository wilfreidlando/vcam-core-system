<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Persistence\Eloquent;

use Core\Identity\Domain\Model\Project;
use Core\Identity\Domain\Port\ProjectRepository;

final class EloquentProjectRepository implements ProjectRepository
{
    public function save(Project $project): void
    {
        ProjectRecord::query()->updateOrCreate(
            ['id' => $project->id],
            [
                'organization_id' => $project->organizationId,
                'name' => $project->name(),
                'status' => $project->status(),
                'status_changed_at' => $project->statusChangedAt,
                'status_reason' => $project->statusReason,
            ],
        );
    }

    public function find(string $id): ?Project
    {
        $record = ProjectRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findAll(?string $organizationId, int $page, int $perPage, ?string $search = null, ?array $organizationIds = null): array
    {
        $query = ProjectRecord::query();
        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }
        if ($search !== null && $search !== '') {
            $query->where('name', 'ilike', '%'.$search.'%');
        }

        // `orderBy`/`skip`/`take`/`count` are forwarded by Eloquent\Builder
        // to the underlying query builder rather than declared on it
        // directly; going through `toBase()` first avoids a Larastan false
        // positive ("dynamic call to static method") on those forwarded
        // calls, and `ProjectRecord::hydrate()` restores the casts after.
        $base = $query->toBase();

        // ADR-0069 §2 — the account's scope narrows the query itself, so the
        // paginated `total` is scoped like the rows. `null` = no restriction
        // (a platform scope); an empty list matches nothing.
        if ($organizationIds !== null) {
            $base->whereIn('organization_id', $organizationIds);
        }

        $total = (clone $base)->count();

        // Tiebreaker on the primary key — see EloquentNotificationRepository's
        // identical comment: without it, rows sharing a created_at can
        // duplicate or vanish across pages.
        $base->orderBy('created_at', 'desc');
        $base->orderBy('id', 'desc');
        $base->skip(($page - 1) * $perPage);
        $base->take($perPage);

        $rows = $base->get()->map(static fn (object $row): array => (array) $row)->all();

        $records = ProjectRecord::hydrate($rows);

        $projects = [];
        foreach ($records as $record) {
            $projects[] = $this->toDomain($record);
        }

        return ['items' => $projects, 'total' => $total];
    }

    public function findAllByOrganization(string $organizationId): array
    {
        $rows = ProjectRecord::query()
            ->where('organization_id', $organizationId)
            ->toBase()
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        $projects = [];
        foreach (ProjectRecord::hydrate($rows) as $record) {
            $projects[] = $this->toDomain($record);
        }

        return $projects;
    }

    private function toDomain(ProjectRecord $record): Project
    {
        return new Project(
            $record->id,
            $record->organization_id,
            $record->name,
            $record->status,
            $record->status_changed_at,
            $record->status_reason,
        );
    }
}
