<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Identity\Contracts\ProjectView;
use Core\Identity\Domain\Exception\ProjectNotFound;
use Core\Identity\Domain\Port\ProjectRepository;

final readonly class GetProjectUseCase implements GetProject
{
    public function __construct(
        private ProjectRepository $projects,
    ) {}

    public function get(GetProjectCommand $command): ProjectView
    {
        $project = $this->projects->find($command->projectId)
            ?? throw ProjectNotFound::withId($command->projectId);

        return new ProjectView(
            id: $project->id,
            organizationId: $project->organizationId,
            name: $project->name(),
            status: $project->status(),
        );
    }
}
