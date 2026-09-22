<?php

declare(strict_types=1);

use Core\Identity\Application\UseCase\UpdateOrganization;
use Core\Identity\Contracts\UpdateOrganizationCommand;
use Core\Identity\Domain\Exception\OrganizationNotFound;
use Core\Identity\Domain\Model\Organization;
use Core\Identity\Tests\Unit\Fakes\FixedClock;
use Core\Identity\Tests\Unit\Fakes\InMemoryOrganizationRepository;
use Core\Identity\Tests\Unit\Fakes\RecordingAuditLogger;

it('renames and changes the status of an existing organization, and audits before/after', function (): void {
    $organizations = new InMemoryOrganizationRepository;
    $organizations->save(Organization::create('org-1', 'Acme Corp'));

    $audit = new RecordingAuditLogger;
    $useCase = new UpdateOrganization($organizations, $audit, new FixedClock);

    $useCase->update(new UpdateOrganizationCommand('org-1', 'Acme Corp Renamed', 'suspended'), actor: 'operator:1', correlationId: 'corr-1');

    $updated = $organizations->find('org-1');
    assert($updated instanceof Organization);
    expect($updated->name())->toBe('Acme Corp Renamed')
        ->and($updated->status())->toBe('suspended')
        ->and($audit->entries)->toHaveCount(1)
        ->and($audit->entries[0]->action)->toBe('organization.updated')
        ->and($audit->entries[0]->before)->toBe(['name' => 'Acme Corp', 'status' => 'active'])
        ->and($audit->entries[0]->after)->toBe(['name' => 'Acme Corp Renamed', 'status' => 'suspended']);
});

it('throws when the organization does not exist', function (): void {
    $useCase = new UpdateOrganization(new InMemoryOrganizationRepository, new RecordingAuditLogger, new FixedClock);

    $useCase->update(new UpdateOrganizationCommand('missing', 'New Name', 'active'), actor: 'operator:1', correlationId: null);
})->throws(OrganizationNotFound::class);
