<?php

declare(strict_types=1);

use Core\Identity\Application\Dto\CreateOrganizationCommand;
use Core\Identity\Application\UseCase\CreateOrganization;
use Core\Identity\Tests\Unit\Fakes\FixedClock;
use Core\Identity\Tests\Unit\Fakes\InMemoryOrganizationRepository;
use Core\Identity\Tests\Unit\Fakes\RecordingAuditLogger;
use Core\Identity\Tests\Unit\Fakes\SequentialIdentifier;

it('creates and persists an active organization, and audits it', function (): void {
    $organizations = new InMemoryOrganizationRepository;
    $audit = new RecordingAuditLogger;
    $useCase = new CreateOrganization($organizations, new SequentialIdentifier, $audit, new FixedClock);

    $organization = $useCase(new CreateOrganizationCommand('Acme Corp'), actor: 'operator:1', correlationId: 'corr-1');

    expect($organizations->find($organization->id))->not->toBeNull()
        ->and($organization->isActive())->toBeTrue()
        ->and($audit->entries)->toHaveCount(1)
        ->and($audit->entries[0]->action)->toBe('organization.created')
        ->and($audit->entries[0]->resource)->toBe('organization:'.$organization->id);
});
