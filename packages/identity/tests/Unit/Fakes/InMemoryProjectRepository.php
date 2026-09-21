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

    public function findAll(?string $organizationId, int $page, int $perPage, ?string $search = null): array
    {
        $matching = array_values(array_filter(
            $this->projects,
            static fn (Project $project): bool => ($organizationId === null || $project->organizationId === $organizationId)
                && ($search === null || $search === '' || stripos($project->name(), $search) !== false),
        ));

        $total = count($matching);
        $offset = ($page - 1) * $perPage;

        return ['items' => array_slice($matching, $offset, $perPage), 'total' => $total];
    }
}
