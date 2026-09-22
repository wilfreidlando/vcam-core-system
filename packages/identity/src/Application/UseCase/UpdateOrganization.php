<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\UpdateOrganization as UpdateOrganizationPort;
use Core\Identity\Contracts\UpdateOrganizationCommand;
use Core\Identity\Domain\Exception\OrganizationNotFound;
use Core\Identity\Domain\Model\Organization;
use Core\Identity\Domain\Port\OrganizationRepository;
use Core\Support\Clock;

/**
 * Profile-only update (name, status) — mirrors `UpdateOperator`. Reachable
 * from the admin console's self-service endpoint; no CLI command exists
 * for this one (unlike `CreateOrganization`), since renaming/suspending an
 * existing organization is an operator-console action, not a provisioning
 * step.
 */
final readonly class UpdateOrganization implements UpdateOrganizationPort
{
    public function __construct(
        private OrganizationRepository $organizations,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function update(UpdateOrganizationCommand $command, string $actor, ?string $correlationId): void
    {
        $organization = $this->organizations->find($command->organizationId)
            ?? throw OrganizationNotFound::withId($command->organizationId);

        $before = ['name' => $organization->name(), 'status' => $organization->status()];

        $updated = new Organization($organization->id, $command->name, $command->status);

        $this->organizations->save($updated);

        $this->audit->record(new AuditEntry(
            actor: $actor,
            action: 'organization.updated',
            resource: 'organization:'.$organization->id,
            before: $before,
            after: ['name' => $updated->name(), 'status' => $updated->status()],
            context: [],
            correlationId: $correlationId,
            occurredAt: $this->clock->now(),
        ));
    }
}
