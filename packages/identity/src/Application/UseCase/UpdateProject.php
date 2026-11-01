<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\UpdateProject as UpdateProjectPort;
use Core\Identity\Contracts\UpdateProjectCommand;
use Core\Identity\Domain\Exception\ProjectNotFound;
use Core\Identity\Domain\Model\Project;
use Core\Identity\Domain\Model\ProjectStatus;
use Core\Identity\Domain\Port\ApiKeyRepository;
use Core\Identity\Domain\Port\ProjectRepository;
use Core\Support\Clock;
use Core\Support\UnitOfWork;

/**
 * Profile-only update (name, status) — mirrors `UpdateOrganization`/
 * `UpdateOperator`. A Project never changes `organizationId` through this
 * path; only its name and status.
 *
 * ADR-0069 §3 — archiving is final and takes the project's credentials
 * with it: every API key of the project is revoked in the same
 * transaction, each revocation audited. (The webhook endpoints stop
 * receiving on their side: the webhook module fans nothing out for an
 * archived project.)
 */
final readonly class UpdateProject implements UpdateProjectPort
{
    public function __construct(
        private ProjectRepository $projects,
        private AuditLogger $audit,
        private Clock $clock,
        private ApiKeyRepository $apiKeys,
        private UnitOfWork $unitOfWork,
    ) {}

    public function update(UpdateProjectCommand $command, string $actor, ?string $correlationId): void
    {
        $project = $this->projects->find($command->projectId)
            ?? throw ProjectNotFound::withId($command->projectId);

        $before = ['name' => $project->name(), 'status' => $project->status()];

        // ADR-0069 §3: a status change goes through the model, which
        // enforces the lifecycle (active ⇄ suspended, → archived, archived
        // is final). The reason is mandatory and audited.
        $updated = $project->status() === $command->status
            ? new Project($project->id, $project->organizationId, $command->name, $command->status, $project->statusChangedAt, $project->statusReason)
            : $project->changeStatus(
                ProjectStatus::from($command->status),
                $this->clock->now(),
                $command->reason ?? '',
            );
        $updated = new Project($updated->id, $updated->organizationId, $command->name, $updated->status(), $updated->statusChangedAt, $updated->statusReason);

        $this->unitOfWork->transactional(function () use ($project, $updated, $before, $command, $actor, $correlationId): void {
            $this->projects->save($updated);

            if (! $project->isArchived() && $updated->isArchived()) {
                $this->revokeEveryKey($updated, $command->reason ?? '', $actor, $correlationId);
            }

            $this->audit->record(new AuditEntry(
                actor: $actor,
                action: 'project.updated',
                resource: 'project:'.$project->id,
                before: $before,
                after: ['name' => $updated->name(), 'status' => $updated->status()],
                context: $command->reason !== null && $command->reason !== '' ? ['reason' => $command->reason] : [],
                correlationId: $correlationId,
                occurredAt: $this->clock->now(),
                projectId: $project->id,
                organizationId: $project->organizationId,
            ));
        });
    }

    private function revokeEveryKey(Project $project, string $reason, string $actor, ?string $correlationId): void
    {
        foreach ($this->apiKeys->findAllByProject($project->id) as $apiKey) {
            $this->apiKeys->delete($apiKey->id);

            $this->audit->record(new AuditEntry(
                actor: $actor,
                action: 'api_key.revoked',
                resource: 'api_key:'.$apiKey->id,
                before: ['project_id' => $apiKey->projectId, 'prefix' => $apiKey->prefix, 'name' => $apiKey->name, 'scopes' => $apiKey->scopes()],
                after: null,
                context: ['reason' => 'Projet archivé : '.mb_substr($reason, 0, 480)],
                correlationId: $correlationId,
                occurredAt: $this->clock->now(),
                projectId: $project->id,
                organizationId: $project->organizationId,
            ));
        }
    }
}
