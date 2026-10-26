<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Contracts\ListOrganizations;
use Core\Identity\Contracts\ListOrganizationsCommand;
use Core\Identity\Contracts\OrganizationPage;
use Core\Identity\Contracts\OrganizationView;
use Core\Identity\Domain\Model\Organization;
use Core\Identity\Domain\Port\OrganizationRepository;
use Core\Support\Access\AccessScope;

final readonly class ListOrganizationsUseCase implements ListOrganizations
{
    public function __construct(
        private OrganizationRepository $organizations,
    ) {}

    public function list(ListOrganizationsCommand $command, AccessScope $scope): OrganizationPage
    {
        // ADR-0069 §2 — only the organizations the account belongs to.
        $result = $this->organizations->findAll($command->page, $command->perPage, $command->search, $scope->organizationFilter());

        $items = array_map(
            static fn (Organization $organization): OrganizationView => new OrganizationView(
                id: $organization->id,
                name: $organization->name(),
                status: $organization->status(),
            ),
            array_values(array_filter(
                $result['items'],
                static fn (Organization $organization): bool => $scope->allowsOrganization($organization->id),
            )),
        );

        return new OrganizationPage($items, $result['total'], $command->page, $command->perPage);
    }
}
