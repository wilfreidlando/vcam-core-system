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
            ['organization_id' => $project->organizationId, 'name' => $project->name(), 'status' => $project->status()],
        );
    }

    public function find(string $id): ?Project
    {
        $record = ProjectRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findAll(?string $organizationId, int $page, int $perPage): array
    {
        $query = ProjectRecord::query();
        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        // `orderBy`/`skip`/`take`/`count` are forwarded by Eloquent\Builder
        // to the underlying query builder rather than declared on it
        // directly; going through `toBase()` first avoids a Larastan false
        // positive ("dynamic call to static method") on those forwarded
        // calls, and `ProjectRecord::hydrate()` restores the casts after.
        $base = $query->toBase();

        $total = (clone $base)->count();

        $base->orderBy('created_at', 'desc');
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

    private function toDomain(ProjectRecord $record): Project
    {
        return new Project($record->id, $record->organization_id, $record->name, $record->status);
    }
}
