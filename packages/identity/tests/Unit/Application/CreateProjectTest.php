<?php

declare(strict_types=1);

use Core\Identity\Application\UseCase\CreateProject;
use Core\Identity\Application\UseCase\IssueApiKey;
use Core\Identity\Contracts\ApiKeyScopes;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Domain\Exception\OrganizationNotFound;
use Core\Identity\Domain\Model\Organization;
use Core\Identity\Tests\Unit\Fakes\FixedApiKeySecretGenerator;
use Core\Identity\Tests\Unit\Fakes\FixedClock;
use Core\Identity\Tests\Unit\Fakes\InMemoryApiKeyRepository;
use Core\Identity\Tests\Unit\Fakes\InMemoryOrganizationRepository;
use Core\Identity\Tests\Unit\Fakes\InMemoryProjectRepository;
use Core\Identity\Tests\Unit\Fakes\PassthroughUnitOfWork;
use Core\Identity\Tests\Unit\Fakes\RecordingAuditLogger;
use Core\Identity\Tests\Unit\Fakes\SequentialIdentifier;

it('creates a project under an existing organization, provisions a sandbox and a live API key, and audits all three', function (): void {
    $organizations = new InMemoryOrganizationRepository;
    $organizations->save(Organization::create('org-1', 'Acme Corp'));

    $projects = new InMemoryProjectRepository;
    $apiKeys = new InMemoryApiKeyRepository;
    $audit = new RecordingAuditLogger;
    $identifier = new SequentialIdentifier;
    $clock = new FixedClock;
    $issueApiKey = new IssueApiKey($projects, $apiKeys, new FixedApiKeySecretGenerator, $identifier, $audit, $clock, new PassthroughUnitOfWork);
    $useCase = new CreateProject($organizations, $projects, $issueApiKey, $identifier, $audit, $clock);

    $provisioned = $useCase->create(new CreateProjectCommand('org-1', 'Tourism Platform'), actor: 'operator:1', correlationId: 'corr-1');

    expect($projects->find($provisioned->project->id))->not->toBeNull()
        ->and($provisioned->project->organizationId)->toBe('org-1')
        ->and($provisioned->sandboxApiKey->apiKeyId)->not->toBe($provisioned->liveApiKey->apiKeyId)
        ->and($apiKeys->find($provisioned->sandboxApiKey->apiKeyId)?->scopes())->toBe(ApiKeyScopes::ALL)
        ->and($apiKeys->find($provisioned->liveApiKey->apiKeyId)?->scopes())->toBe(ApiKeyScopes::ALL)
        ->and($audit->entries[0]->action)->toBe('project.created')
        ->and(array_map(fn ($entry) => $entry->action, $audit->entries))->toBe(['project.created', 'api_key.issued', 'api_key.issued']);
});

it('refuses to create a project under an unknown organization', function (): void {
    $projects = new InMemoryProjectRepository;
    $apiKeys = new InMemoryApiKeyRepository;
    $audit = new RecordingAuditLogger;
    $identifier = new SequentialIdentifier;
    $clock = new FixedClock;
    $useCase = new CreateProject(
        new InMemoryOrganizationRepository,
        $projects,
        new IssueApiKey($projects, $apiKeys, new FixedApiKeySecretGenerator, $identifier, $audit, $clock, new PassthroughUnitOfWork),
        $identifier,
        $audit,
        $clock,
    );

    $useCase->create(new CreateProjectCommand('missing-org', 'Some Project'), actor: 'operator:1', correlationId: null);
})->throws(OrganizationNotFound::class);
