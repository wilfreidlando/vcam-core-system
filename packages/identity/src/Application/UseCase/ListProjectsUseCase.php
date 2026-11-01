<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Contracts\ListProjects as ListProjectsPort;
use Core\Identity\Contracts\ListProjectsCommand;
use Core\Identity\Contracts\ProjectPage;
use Core\Identity\Contracts\ProjectView;
use Core\Identity\Domain\Model\Project;
use Core\Identity\Domain\Port\ProjectRepository;
use Core\Support\Access\AccessScope;

final readonly class ListProjectsUseCase implements ListProjectsPort
{
    public function __construct(
        private ProjectRepository $projects,
    ) {}

    public function list(ListProjectsCommand $command, AccessScope $scope): ProjectPage
    {
        // ADR-0069 §2 — the listing never crosses the caller's scope, and
        // an explicit organization filter outside the scope yields
        // nothing rather than the other tenant's projects.
        $organizationId = $command->organizationId;
        if ($organizationId !== null && ! $scope->allowsOrganization($organizationId)) {
            return new ProjectPage([], 0, $command->page, $command->perPage);
        }

        $result = $this->projects->findAll($organizationId, $command->page, $command->perPage, $command->search, $scope->organizationFilter());

        $items = array_map(
            static fn (Project $project): ProjectView => new ProjectView(
                id: $project->id,
                organizationId: $project->organizationId,
                name: $project->name(),
                status: $project->status(),
            ),
            array_values(array_filter(
                $result['items'],
                static fn (Project $project): bool => $scope->allowsProject($project->id),
            )),
        );

        return new ProjectPage($items, $result['total'], $command->page, $command->perPage);
    }
}
