<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\UpdateProject as UpdateProjectPort;
use Core\Identity\Contracts\UpdateProjectCommand;
use Core\Identity\Domain\Exception\ProjectNotFound;
use Core\Identity\Domain\Model\Project;
use Core\Identity\Domain\Port\ProjectRepository;
use Core\Support\Clock;

/**
 * Profile-only update (name, status) — mirrors `UpdateOrganization`/
 * `UpdateOperator`. A Project never changes `organizationId` through this
 * path; only its name and status.
 */
final readonly class UpdateProject implements UpdateProjectPort
{
    public function __construct(
        private ProjectRepository $projects,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function update(UpdateProjectCommand $command, string $actor, ?string $correlationId): void
    {
        $project = $this->projects->find($command->projectId)
            ?? throw ProjectNotFound::withId($command->projectId);

        $before = ['name' => $project->name(), 'status' => $project->status()];

        $updated = new Project($project->id, $project->organizationId, $command->name, $command->status);

        $this->projects->save($updated);

        $this->audit->record(new AuditEntry(
            actor: $actor,
            action: 'project.updated',
            resource: 'project:'.$project->id,
            before: $before,
            after: ['name' => $updated->name(), 'status' => $updated->status()],
            context: [],
            correlationId: $correlationId,
            occurredAt: $this->clock->now(),
        ));
    }
}
