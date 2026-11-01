<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Identity\Contracts\ProjectView;
use Core\Identity\Domain\Exception\ProjectNotFound;
use Core\Identity\Domain\Port\ProjectRepository;
use Core\Support\Access\AccessScope;

final readonly class GetProjectUseCase implements GetProject
{
    public function __construct(
        private ProjectRepository $projects,
    ) {}

    public function get(GetProjectCommand $command, AccessScope $scope): ProjectView
    {
        $project = $this->projects->find($command->projectId)
            ?? throw ProjectNotFound::withId($command->projectId);

        // ADR-0069 §2 / S-04: a resource outside the caller's scope is
        // reported as "not found", never as "forbidden" — the answer must
        // not confirm that the resource exists.
        if (! $scope->allowsProject($project->id)) {
            throw ProjectNotFound::withId($command->projectId);
        }

        return new ProjectView(
            id: $project->id,
            organizationId: $project->organizationId,
            name: $project->name(),
            status: $project->status(),
        );
    }
}
