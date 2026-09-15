<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Contracts\ListProjects as ListProjectsPort;
use Core\Identity\Contracts\ListProjectsCommand;
use Core\Identity\Contracts\ProjectPage;
use Core\Identity\Contracts\ProjectView;
use Core\Identity\Domain\Model\Project;
use Core\Identity\Domain\Port\ProjectRepository;

final readonly class ListProjectsUseCase implements ListProjectsPort
{
    public function __construct(
        private ProjectRepository $projects,
    ) {}

    public function list(ListProjectsCommand $command): ProjectPage
    {
        $result = $this->projects->findAll($command->organizationId, $command->page, $command->perPage);

        $items = array_map(
            static fn (Project $project): ProjectView => new ProjectView(
                id: $project->id,
                organizationId: $project->organizationId,
                name: $project->name(),
                status: $project->status(),
            ),
            $result['items'],
        );

        return new ProjectPage($items, $result['total'], $command->page, $command->perPage);
    }
}
