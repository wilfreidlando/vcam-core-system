<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Application\Dto\CreateOrganizationCommand;
use Core\Identity\Domain\Model\Organization;
use Core\Identity\Domain\Port\OrganizationRepository;
use Core\Support\Clock;
use Core\Support\Identifier;

final readonly class CreateOrganization
{
    public function __construct(
        private OrganizationRepository $organizations,
        private Identifier $identifier,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function __invoke(CreateOrganizationCommand $command, string $actor, ?string $correlationId): Organization
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

        return $organization;
    }
}
