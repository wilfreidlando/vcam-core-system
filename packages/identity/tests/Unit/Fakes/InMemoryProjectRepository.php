<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Unit\Fakes;

use Core\Identity\Domain\Model\Project;
use Core\Identity\Domain\Port\ProjectRepository;

final class InMemoryProjectRepository implements ProjectRepository
{
    /** @var array<string, Project> */
    private array $projects = [];

    public function save(Project $project): void
    {
        $this->projects[$project->id] = $project;
    }

    public function find(string $id): ?Project
    {
        return $this->projects[$id] ?? null;
    }
}
