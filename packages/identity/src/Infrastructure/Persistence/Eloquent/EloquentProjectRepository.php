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

    private function toDomain(ProjectRecord $record): Project
    {
        return new Project($record->id, $record->organization_id, $record->name, $record->status);
    }
}
