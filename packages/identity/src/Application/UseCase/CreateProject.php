<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\ApiKeyScopes;
use Core\Identity\Contracts\CreateProject as CreateProjectPort;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Identity\Contracts\ProjectView;
use Core\Identity\Contracts\ProvisionedProjectView;
use Core\Identity\Domain\Exception\OrganizationNotFound;
use Core\Identity\Domain\Model\Project;
use Core\Identity\Domain\Port\OrganizationRepository;
use Core\Identity\Domain\Port\ProjectRepository;
use Core\Support\Clock;
use Core\Support\Identifier;

/**
 * Reachable from both the CLI (`identity:create-project`) and the admin
 * console's self-service endpoint (`POST /v1/admin/projects`) — a single
 * place where a Project is provisioned, whichever caller triggers it.
 * Mirrors `CreateOperator` (ADR-0050).
 *
 * Review finding, user-directed: a Project with no credential is not
 * actually usable by the business app it's meant for — self-service used
 * to stop at the Project row and leave issuing a key as a separate manual
 * step (with an operator hand-typing scopes). Creation now provisions the
 * project's single key (ADR-0066) in the same call, full-access
 * (`ApiKeyScopes::ALL` — see its docblock), a one-shot plaintext value
 * the caller must capture immediately.
 */
final readonly class CreateProject implements CreateProjectPort
{
    public function __construct(
        private OrganizationRepository $organizations,
        private ProjectRepository $projects,
        private IssueApiKey $issueApiKey,
        private Identifier $identifier,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function create(CreateProjectCommand $command, string $actor, ?string $correlationId): ProvisionedProjectView
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

        $apiKey = $this->issueApiKey->issue(
            new IssueApiKeyCommand($project->id, ApiKeyScopes::ALL),
            actor: $actor,
            correlationId: $correlationId,
        );

        return new ProvisionedProjectView(
            project: new ProjectView(
                id: $project->id,
                organizationId: $project->organizationId,
                name: $project->name(),
                status: $project->status(),
            ),
            apiKey: $apiKey,
        );
    }
}
