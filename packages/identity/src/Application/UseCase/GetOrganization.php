<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Contracts\GetOrganization as GetOrganizationPort;
use Core\Identity\Contracts\GetOrganizationCommand;
use Core\Identity\Contracts\OrganizationView;
use Core\Identity\Domain\Exception\OrganizationNotFound;
use Core\Identity\Domain\Port\OrganizationRepository;
use Core\Support\Access\AccessScope;

final readonly class GetOrganization implements GetOrganizationPort
{
    public function __construct(
        private OrganizationRepository $organizations,
    ) {}

    public function get(GetOrganizationCommand $command, AccessScope $scope): OrganizationView
    {
        $organization = $this->organizations->find($command->organizationId)
            ?? throw OrganizationNotFound::withId($command->organizationId);

        // ADR-0069 §2 / S-04 — "not found", never "forbidden".
        if (! $scope->allowsOrganization($organization->id)) {
            throw OrganizationNotFound::withId($command->organizationId);
        }

        return new OrganizationView(
            id: $organization->id,
            name: $organization->name(),
            status: $organization->status(),
        );
    }
}
