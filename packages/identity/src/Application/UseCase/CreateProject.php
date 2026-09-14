<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Application\Dto\CreateProjectCommand;
use Core\Identity\Domain\Exception\OrganizationNotFound;
use Core\Identity\Domain\Model\Project;
use Core\Identity\Domain\Port\OrganizationRepository;
use Core\Identity\Domain\Port\ProjectRepository;
use Core\Support\Clock;
use Core\Support\Identifier;

final readonly class CreateProject
{
    public function __construct(
        private OrganizationRepository $organizations,
        private ProjectRepository $projects,
        private Identifier $identifier,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function __invoke(CreateProjectCommand $command, string $actor, ?string $correlationId): Project
    {
        $organization = $this->organizations->find($command->organizationId)
            ?? throw OrganizationNotFound::withId($command->organizationId);

        $project = Project::create($this->identifier->generate(), $organization->id, $command->name);

        $this->projects->save($project);

        $this->audit->record(new AuditEntry(
            actor: $actor,
            action: 'project.created',
            resource: 'project:'.$project->id,
            before: null,
            after: ['organization_id' => $project->organizationId, 'name' => $project->name(), 'status' => $project->status()],
            context: [],
            correlationId: $correlationId,
            occurredAt: $this->clock->now(),
        ));

        return $project;
    }
}
