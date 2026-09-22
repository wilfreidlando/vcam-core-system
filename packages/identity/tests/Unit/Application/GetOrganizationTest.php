<?php

declare(strict_types=1);

use Core\Identity\Application\UseCase\GetOrganization;
use Core\Identity\Contracts\GetOrganizationCommand;
use Core\Identity\Domain\Exception\OrganizationNotFound;
use Core\Identity\Domain\Model\Organization;
use Core\Identity\Tests\Unit\Fakes\InMemoryOrganizationRepository;

it('resolves an existing organization', function (): void {
    $organizations = new InMemoryOrganizationRepository;
    $organizations->save(Organization::create('org-1', 'Acme Corp'));

    $useCase = new GetOrganization($organizations);

    $view = $useCase->get(new GetOrganizationCommand('org-1'));

    expect($view->id)->toBe('org-1')
        ->and($view->name)->toBe('Acme Corp')
        ->and($view->status)->toBe('active');
});

it('throws when the organization does not exist', function (): void {
    $useCase = new GetOrganization(new InMemoryOrganizationRepository);

    $useCase->get(new GetOrganizationCommand('missing'));
})->throws(OrganizationNotFound::class);
