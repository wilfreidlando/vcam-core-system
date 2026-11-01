<?php

declare(strict_types=1);

use Core\Identity\Contracts\ProjectView;
use Core\Payment\Application\UseCase\RegisterProviderApplication;
use Core\Payment\Contracts\RegisterProviderApplicationCommand;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Tests\Unit\Fakes\FakeGetProject;
use Core\Payment\Tests\Unit\Fakes\InMemoryProviderApplicationRepository;
use Core\Support\Access\AccessScope;

it('registers a provider application for an existing project', function (): void {
    $applications = new InMemoryProviderApplicationRepository;
    $getProject = new FakeGetProject;
    $getProject->withProject(new ProjectView('project-1', 'org-1', 'Acme Corp', 'active'));

    $useCase = new RegisterProviderApplication($applications, $getProject);

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

    $useCase = new RegisterProviderApplication($applications, $getProject);

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
    $useCase = new RegisterProviderApplication(new InMemoryProviderApplicationRepository, new FakeGetProject);

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

    $useCase = new RegisterProviderApplication($applications, $getProject);

    expect(fn () => $useCase->register(new RegisterProviderApplicationCommand(
        projectId: 'project-b',
        providerCode: 'my-coolpay',
        publicKey: 'pub-key',
        privateKey: 'super-secret',
    ), AccessScope::forProject('project-a')))->toThrow(RuntimeException::class, 'Project not found: project-b');

    expect($applications->findByProjectAndProvider('project-b', 'my-coolpay'))->toBeNull();
});

it('never names another tenant\'s project when a public key is already used (S-01)', function (): void {
    $applications = new InMemoryProviderApplicationRepository;
    $getProject = new FakeGetProject;
    $getProject->withProject(new ProjectView('project-a', 'org-a', 'Tenant A', 'active'));
    $getProject->withProject(new ProjectView('project-b', 'org-b', 'Secret Tenant B', 'active'));

    $useCase = new RegisterProviderApplication($applications, $getProject);
    $useCase->register(new RegisterProviderApplicationCommand(
        projectId: 'project-b',
        providerCode: 'my-coolpay',
        publicKey: 'shared-pub-key',
        privateKey: 'b-secret',
    ), AccessScope::platform());

    try {
        $useCase->register(new RegisterProviderApplicationCommand(
            projectId: 'project-a',
            providerCode: 'my-coolpay',
            publicKey: 'shared-pub-key',
            privateKey: 'a-secret',
        ), AccessScope::forProject('project-a'));
        throw new LogicException('The duplicate public key must be refused.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('un autre projet')
            ->and($e->getMessage())->not->toContain('Secret Tenant B')
            ->and($e->getMessage())->not->toContain('project-b');
    }
});

it('names the owner project when the caller may see it', function (): void {
    $applications = new InMemoryProviderApplicationRepository;
    $getProject = new FakeGetProject;
    $getProject->withProject(new ProjectView('project-a', 'org-a', 'Tenant A', 'active'));
    $getProject->withProject(new ProjectView('project-a2', 'org-a', 'Tenant A bis', 'active'));

    $useCase = new RegisterProviderApplication($applications, $getProject);
    $useCase->register(new RegisterProviderApplicationCommand(
        projectId: 'project-a2',
        providerCode: 'my-coolpay',
        publicKey: 'shared-pub-key',
        privateKey: 'a2-secret',
    ), AccessScope::platform());

    expect(fn () => $useCase->register(new RegisterProviderApplicationCommand(
        projectId: 'project-a',
        providerCode: 'my-coolpay',
        publicKey: 'shared-pub-key',
        privateKey: 'a-secret',
    ), AccessScope::platform()))->toThrow(RuntimeException::class, 'Tenant A bis');
});
