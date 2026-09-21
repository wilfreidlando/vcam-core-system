<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Contracts\ListOrganizations;
use Core\Identity\Contracts\ListOrganizationsCommand;
use Core\Identity\Contracts\OrganizationPage;
use Core\Identity\Contracts\OrganizationView;
use Core\Identity\Domain\Model\Organization;
use Core\Identity\Domain\Port\OrganizationRepository;

final readonly class ListOrganizationsUseCase implements ListOrganizations
{
    public function __construct(
        private OrganizationRepository $organizations,
    ) {}

    public function list(ListOrganizationsCommand $command): OrganizationPage
    {
        $result = $this->organizations->findAll($command->page, $command->perPage, $command->search);

        $items = array_map(
            static fn (Organization $organization): OrganizationView => new OrganizationView(
                id: $organization->id,
                name: $organization->name(),
                status: $organization->status(),
            ),
            $result['items'],
        );

        return new OrganizationPage($items, $result['total'], $command->page, $command->perPage);
    }
}
