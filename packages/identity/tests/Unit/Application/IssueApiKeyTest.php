<?php

declare(strict_types=1);

use Core\Identity\Application\UseCase\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Identity\Domain\Exception\ProjectNotActive;
use Core\Identity\Domain\Exception\ProjectNotFound;
use Core\Identity\Domain\Model\ApiKey;
use Core\Identity\Domain\Model\Project;
use Core\Identity\Tests\Unit\Fakes\FixedApiKeySecretGenerator;
use Core\Identity\Tests\Unit\Fakes\FixedClock;
use Core\Identity\Tests\Unit\Fakes\InMemoryApiKeyRepository;
use Core\Identity\Tests\Unit\Fakes\InMemoryProjectRepository;
use Core\Identity\Tests\Unit\Fakes\RecordingAuditLogger;
use Core\Identity\Tests\Unit\Fakes\SequentialIdentifier;
use Core\Support\Environment;

final readonly class IssueApiKeyFixture
{
    public function __construct(
        public IssueApiKey $useCase,
        public InMemoryProjectRepository $projects,
        public InMemoryApiKeyRepository $apiKeys,
        public RecordingAuditLogger $audit,
    ) {}

    public static function make(): self
    {
        $projects = new InMemoryProjectRepository;
        $apiKeys = new InMemoryApiKeyRepository;
        $audit = new RecordingAuditLogger;

        $useCase = new IssueApiKey(
            $projects,
            $apiKeys,
            new FixedApiKeySecretGenerator,
            new SequentialIdentifier,
            $audit,
            new FixedClock,
        );

        return new self($useCase, $projects, $apiKeys, $audit);
    }
}

it('persists the hash, never the plaintext secret', function (): void {
    $fixture = IssueApiKeyFixture::make();
    $fixture->projects->save(Project::create('project-1', 'org-1', 'Test Project'));

    $issued = $fixture->useCase->issue(
        new IssueApiKeyCommand('project-1', Environment::Sandbox, ['payments:write']),
        actor: 'operator:1',
        correlationId: 'corr-1',
    );

    $stored = $fixture->apiKeys->find($issued->apiKeyId);
    assert($stored instanceof ApiKey);

    expect($stored->matchesSecret('fixed-test-secret'))->toBeTrue()
        ->and($issued->plaintextToken)->toContain('fixed-test-secret');
});

it('records an audit entry without the secret or its hash', function (): void {
    $fixture = IssueApiKeyFixture::make();
    $fixture->projects->save(Project::create('project-1', 'org-1', 'Test Project'));

    $fixture->useCase->issue(
        new IssueApiKeyCommand('project-1', Environment::Sandbox, ['payments:write']),
        actor: 'operator:1',
        correlationId: 'corr-1',
    );

    expect($fixture->audit->entries)->toHaveCount(1);

    $entry = $fixture->audit->entries[0];
    $payload = json_encode($entry->after);

    expect($entry->action)->toBe('api_key.issued')
        ->and($entry->correlationId)->toBe('corr-1')
        ->and($payload)->not->toContain('fixed-test-secret');
});

it('refuses to issue a key for an unknown project', function (): void {
    $fixture = IssueApiKeyFixture::make();

    $fixture->useCase->issue(
        new IssueApiKeyCommand('missing-project', Environment::Sandbox, []),
        actor: 'operator:1',
        correlationId: null,
    );
})->throws(ProjectNotFound::class);

it('refuses to issue a key for an inactive project', function (): void {
    $fixture = IssueApiKeyFixture::make();
    $fixture->projects->save(new Project('project-1', 'org-1', 'Suspended Project', 'suspended'));

    $fixture->useCase->issue(
        new IssueApiKeyCommand('project-1', Environment::Sandbox, []),
        actor: 'operator:1',
        correlationId: null,
    );
})->throws(ProjectNotActive::class);
