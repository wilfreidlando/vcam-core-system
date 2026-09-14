<?php

declare(strict_types=1);

use Core\Identity\Application\Dto\CreateProjectCommand;
use Core\Identity\Application\UseCase\CreateProject;
use Core\Identity\Domain\Exception\OrganizationNotFound;
use Core\Identity\Domain\Model\Organization;
use Core\Identity\Tests\Unit\Fakes\FixedClock;
use Core\Identity\Tests\Unit\Fakes\InMemoryOrganizationRepository;
use Core\Identity\Tests\Unit\Fakes\InMemoryProjectRepository;
use Core\Identity\Tests\Unit\Fakes\RecordingAuditLogger;
use Core\Identity\Tests\Unit\Fakes\SequentialIdentifier;

it('creates a project under an existing organization, and audits it', function (): void {
    $organizations = new InMemoryOrganizationRepository;
    $organizations->save(Organization::create('org-1', 'Acme Corp'));

    $projects = new InMemoryProjectRepository;
    $audit = new RecordingAuditLogger;
    $useCase = new CreateProject($organizations, $projects, new SequentialIdentifier, $audit, new FixedClock);

    $project = $useCase(new CreateProjectCommand('org-1', 'Tourism Platform'), actor: 'operator:1', correlationId: 'corr-1');

    expect($projects->find($project->id))->not->toBeNull()
        ->and($project->organizationId)->toBe('org-1')
        ->and($audit->entries[0]->action)->toBe('project.created');
});

it('refuses to create a project under an unknown organization', function (): void {
    $useCase = new CreateProject(
        new InMemoryOrganizationRepository,
        new InMemoryProjectRepository,
        new SequentialIdentifier,
        new RecordingAuditLogger,
        new FixedClock,
    );

    $useCase(new CreateProjectCommand('missing-org', 'Some Project'), actor: 'operator:1', correlationId: null);
})->throws(OrganizationNotFound::class);
