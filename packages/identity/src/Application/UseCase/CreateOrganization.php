<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\CreateOrganization as CreateOrganizationPort;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\OrganizationView;
use Core\Identity\Domain\Model\Organization;
use Core\Identity\Domain\Port\OrganizationRepository;
use Core\Support\Clock;
use Core\Support\Identifier;

/**
 * Reachable from both the CLI (`identity:create-organization`) and the
 * admin console's self-service endpoint (`POST /v1/admin/organizations`)
 * — a single place where an organization is provisioned, whichever
 * caller triggers it. Mirrors `CreateOperator` (ADR-0050).
 */
final readonly class CreateOrganization implements CreateOrganizationPort
{
    public function __construct(
        private OrganizationRepository $organizations,
        private Identifier $identifier,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function create(CreateOrganizationCommand $command, string $actor, ?string $correlationId): OrganizationView
    {
        $organization = Organization::create($this->identifier->generate(), $command->name);

        $this->organizations->save($organization);

        $this->audit->record(new AuditEntry(
            actor: $actor,
            action: 'organization.created',
            resource: 'organization:'.$organization->id,
            before: null,
            after: ['name' => $organization->name(), 'status' => $organization->status()],
            context: [],
            correlationId: $correlationId,
            occurredAt: $this->clock->now(),
        ));

        return new OrganizationView(
            id: $organization->id,
            name: $organization->name(),
            status: $organization->status(),
        );
    }
}
