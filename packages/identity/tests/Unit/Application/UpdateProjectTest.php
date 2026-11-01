<?php

declare(strict_types=1);

use Core\Identity\Application\UseCase\UpdateProject;
use Core\Identity\Contracts\UpdateProjectCommand;
use Core\Identity\Domain\Exception\ProjectNotFound;
use Core\Identity\Domain\Model\ApiKey;
use Core\Identity\Domain\Model\Project;
use Core\Identity\Tests\Unit\Fakes\FixedClock;
use Core\Identity\Tests\Unit\Fakes\InMemoryApiKeyRepository;
use Core\Identity\Tests\Unit\Fakes\InMemoryProjectRepository;
use Core\Identity\Tests\Unit\Fakes\PassthroughUnitOfWork;
use Core\Identity\Tests\Unit\Fakes\RecordingAuditLogger;

it('renames and changes the status of an existing project, without touching its organization, and audits before/after', function (): void {
    $projects = new InMemoryProjectRepository;
    $projects->save(Project::create('project-1', 'org-1', 'Tourism Platform'));

    $audit = new RecordingAuditLogger;
    $useCase = new UpdateProject($projects, $audit, new FixedClock, new InMemoryApiKeyRepository, new PassthroughUnitOfWork);

    // ADR-0069 §3 — a status change goes through the lifecycle, which
    // requires a reason (it is audited).
    $useCase->update(
        new UpdateProjectCommand('project-1', 'Tourism Platform Renamed', 'suspended', reason: 'Facturation bloquée'),
        actor: 'operator:1',
        correlationId: 'corr-1',
    );

    $updated = $projects->find('project-1');
    assert($updated instanceof Project);
    expect($updated->name())->toBe('Tourism Platform Renamed')
        ->and($updated->status())->toBe('suspended')
        ->and($updated->organizationId)->toBe('org-1')
        ->and($audit->entries)->toHaveCount(1)
        ->and($audit->entries[0]->action)->toBe('project.updated')
        ->and($audit->entries[0]->before)->toBe(['name' => 'Tourism Platform', 'status' => 'active'])
        ->and($audit->entries[0]->after)->toBe(['name' => 'Tourism Platform Renamed', 'status' => 'suspended'])
        ->and($audit->entries[0]->context)->toBe(['reason' => 'Facturation bloquée']);
});

it('throws when the project does not exist', function (): void {
    $useCase = new UpdateProject(new InMemoryProjectRepository, new RecordingAuditLogger, new FixedClock, new InMemoryApiKeyRepository, new PassthroughUnitOfWork);

    $useCase->update(new UpdateProjectCommand('missing', 'New Name', 'active'), actor: 'operator:1', correlationId: null);
})->throws(ProjectNotFound::class);

it('refuses a status change without a reason, and refuses a transition the lifecycle forbids', function (): void {
    $projects = new InMemoryProjectRepository;
    $projects->save(Project::create('project-1', 'org-1', 'Tourism Platform'));
    $useCase = new UpdateProject($projects, new RecordingAuditLogger, new FixedClock, new InMemoryApiKeyRepository, new PassthroughUnitOfWork);

    expect(fn () => $useCase->update(
        new UpdateProjectCommand('project-1', 'Tourism Platform', 'suspended'),
        actor: 'operator:1',
        correlationId: null,
    ))->toThrow(Core\Identity\Domain\Exception\ProjectStatusChangeRequiresReason::class);

    // archived is final: nothing leaves it (ADR-0069 §3).
    $useCase->update(new UpdateProjectCommand('project-1', 'Tourism Platform', 'archived', reason: 'Fin'), actor: 'operator:1', correlationId: null);
    expect(fn () => $useCase->update(
        new UpdateProjectCommand('project-1', 'Tourism Platform', 'active', reason: 'Retour'),
        actor: 'operator:1',
        correlationId: null,
    ))->toThrow(Core\Identity\Domain\Exception\InvalidProjectStatusTransition::class);
});

it('revokes every key of the project when it is archived, each revocation audited (ADR-0069 §3)', function (): void {
    $projects = new InMemoryProjectRepository;
    $projects->save(Project::create('project-1', 'org-1', 'Tourism Platform'));
    $apiKeys = new InMemoryApiKeyRepository;
    foreach (['key-1', 'key-2'] as $i => $id) {
        $apiKeys->save(ApiKey::issue(
            id: $id,
            projectId: 'project-1',
            prefix: 'pk_live_abcd000'.$i,
            secretHash: ApiKey::hash('secret-'.$id),
            scopes: [],
            issuedAt: new DateTimeImmutable('2026-09-15T10:00:00Z'),
        ));
    }
    $apiKeys->save(ApiKey::issue(
        id: 'key-other',
        projectId: 'project-2',
        prefix: 'pk_live_other000',
        secretHash: ApiKey::hash('secret-other'),
        scopes: [],
        issuedAt: new DateTimeImmutable('2026-09-15T10:00:00Z'),
    ));
    $audit = new RecordingAuditLogger;
    $useCase = new UpdateProject($projects, $audit, new FixedClock, $apiKeys, new PassthroughUnitOfWork);

    // Suspending keeps the keys: the project may come back.
    $useCase->update(new UpdateProjectCommand('project-1', 'Tourism Platform', 'suspended', reason: 'Impayé'), actor: 'operator:1', correlationId: null);
    expect($apiKeys->findAllByProject('project-1'))->toHaveCount(2);

    $useCase->update(new UpdateProjectCommand('project-1', 'Tourism Platform', 'archived', reason: 'Fin de contrat'), actor: 'operator:1', correlationId: null);

    $revocations = array_values(array_filter($audit->entries, static fn ($entry): bool => $entry->action === 'api_key.revoked'));

    expect($apiKeys->findAllByProject('project-1'))->toBe([])
        ->and($apiKeys->find('key-other'))->not->toBeNull()
        ->and($revocations)->toHaveCount(2)
        ->and($revocations[0]->projectId)->toBe('project-1')
        ->and($revocations[0]->organizationId)->toBe('org-1')
        ->and($revocations[0]->context)->toBe(['reason' => 'Projet archivé : Fin de contrat']);
});
