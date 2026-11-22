<?php

declare(strict_types=1);

use Core\Identity\Contracts\ProjectView;
use Core\Payment\Application\UseCase\RegisterProviderApplication;
use Core\Payment\Contracts\RegisterProviderApplicationCommand;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Tests\Unit\Fakes\FakeGetProject;
use Core\Payment\Tests\Unit\Fakes\InMemoryProviderApplicationRepository;
use Core\Support\Access\AccessScope;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

it('registers a provider application for an existing project', function (): void {
    $applications = new InMemoryProviderApplicationRepository;
    $getProject = new FakeGetProject;
    $getProject->withProject(new ProjectView('project-1', 'org-1', 'Acme Corp', 'active'));

    $useCase = new RegisterProviderApplication($applications, $getProject, new NullLogger);

    $useCase->register(new RegisterProviderApplicationCommand(
        projectId: 'project-1',
        providerCode: 'my-coolpay',
        publicKey: 'pub-key',
        privateKey: 'super-secret',
    ), AccessScope::platform());

    $application = $applications->findByProjectAndProvider('project-1', 'my-coolpay');
    assert($application instanceof ProviderApplication);

    expect($application->projectId)->toBe('project-1')
        ->and($application->providerCode)->toBe('my-coolpay')
        ->and($application->publicKey)->toBe('pub-key')
        ->and($application->privateKey)->toBe('super-secret');
});

it('rotates an existing provider application (save() is an upsert)', function (): void {
    $applications = new InMemoryProviderApplicationRepository;
    $getProject = new FakeGetProject;
    $getProject->withProject(new ProjectView('project-1', 'org-1', 'Acme Corp', 'active'));

    $useCase = new RegisterProviderApplication($applications, $getProject, new NullLogger);

    $useCase->register(new RegisterProviderApplicationCommand(
        projectId: 'project-1',
        providerCode: 'my-coolpay',
        publicKey: 'old-pub-key',
        privateKey: 'old-secret',
    ), AccessScope::platform());

    $useCase->register(new RegisterProviderApplicationCommand(
        projectId: 'project-1',
        providerCode: 'my-coolpay',
        publicKey: 'new-pub-key',
        privateKey: 'new-secret',
    ), AccessScope::platform());

    $application = $applications->findByProjectAndProvider('project-1', 'my-coolpay');
    assert($application instanceof ProviderApplication);

    expect($application->publicKey)->toBe('new-pub-key')
        ->and($application->privateKey)->toBe('new-secret');
});

it('refuses to register a provider application for an unknown project', function (): void {
    $useCase = new RegisterProviderApplication(new InMemoryProviderApplicationRepository, new FakeGetProject, new NullLogger);

    $useCase->register(new RegisterProviderApplicationCommand(
        projectId: 'missing-project',
        providerCode: 'my-coolpay',
        publicKey: 'pub-key',
        privateKey: 'super-secret',
    ), AccessScope::platform());
})->throws(RuntimeException::class);

it('refuses a project outside the caller scope exactly like an unknown one (ADR-0069 §2)', function (): void {
    $applications = new InMemoryProviderApplicationRepository;
    $getProject = new FakeGetProject;
    $getProject->withProject(new ProjectView('project-b', 'org-b', 'Tenant B', 'active'));

    $useCase = new RegisterProviderApplication($applications, $getProject, new NullLogger);

    expect(fn () => $useCase->register(new RegisterProviderApplicationCommand(
        projectId: 'project-b',
        providerCode: 'my-coolpay',
        publicKey: 'pub-key',
        privateKey: 'super-secret',
    ), AccessScope::forProject('project-a')))->toThrow(RuntimeException::class, 'Project not found: project-b');

    expect($applications->findByProjectAndProvider('project-b', 'my-coolpay'))->toBeNull();
});

it('lets two projects register the same public key (ADR-0126)', function (): void {
    $applications = new InMemoryProviderApplicationRepository;
    $getProject = new FakeGetProject;
    $getProject->withProject(new ProjectView('project-a', 'org-a', 'Tenant A', 'active'));
    $getProject->withProject(new ProjectView('project-b', 'org-b', 'Tenant B', 'active'));

    $useCase = new RegisterProviderApplication($applications, $getProject, new NullLogger);

    $useCase->register(new RegisterProviderApplicationCommand(
        projectId: 'project-b',
        providerCode: 'my-coolpay',
        publicKey: 'shared-pub-key',
        privateKey: 'b-secret',
    ), AccessScope::platform());

    $useCase->register(new RegisterProviderApplicationCommand(
        projectId: 'project-a',
        providerCode: 'my-coolpay',
        publicKey: 'shared-pub-key',
        privateKey: 'a-secret',
    ), AccessScope::forProject('project-a'));

    $candidates = $applications->findAllByProviderAndPublicKey('my-coolpay', 'shared-pub-key');

    expect($candidates)->toHaveCount(2)
        ->and($applications->findByProjectAndProvider('project-a', 'my-coolpay')?->publicKey)->toBe('shared-pub-key')
        ->and($applications->findByProjectAndProvider('project-b', 'my-coolpay')?->publicKey)->toBe('shared-pub-key');
});

it('never names another tenant\'s project when a public key is shared (S-01)', function (): void {
    $applications = new InMemoryProviderApplicationRepository;
    $getProject = new FakeGetProject;
    $getProject->withProject(new ProjectView('project-a', 'org-a', 'Tenant A', 'active'));
    $getProject->withProject(new ProjectView('project-b', 'org-b', 'Secret Tenant B', 'active'));

    $logger = new class extends AbstractLogger
    {
        /** @var list<array{level: mixed, message: string|Stringable, context: array<mixed>}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
        }
    };

    $useCase = new RegisterProviderApplication($applications, $getProject, $logger);
    $useCase->register(new RegisterProviderApplicationCommand(
        projectId: 'project-b',
        providerCode: 'my-coolpay',
        publicKey: 'shared-pub-key',
        privateKey: 'b-secret',
    ), AccessScope::platform());

    // The registration succeeds — but the warning the operator sees must
    // not identify the other tenant (ADR-0069 §2, S-01).
    $useCase->register(new RegisterProviderApplicationCommand(
        projectId: 'project-a',
        providerCode: 'my-coolpay',
        publicKey: 'shared-pub-key',
        privateKey: 'a-secret',
    ), AccessScope::forProject('project-a'));

    expect($applications->findByProjectAndProvider('project-a', 'my-coolpay'))->not->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and((string) $logger->records[0]['message'])->toBe('payment.provider_application.shared_public_key')
        ->and(json_encode($logger->records[0]['context']))->not->toContain('project-b')
        ->and(json_encode($logger->records[0]['context']))->not->toContain('Secret Tenant B');
});
