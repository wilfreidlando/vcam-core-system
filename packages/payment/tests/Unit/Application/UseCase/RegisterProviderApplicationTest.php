<?php

declare(strict_types=1);

use Core\Identity\Contracts\ProjectView;
use Core\Payment\Application\UseCase\RegisterProviderApplication;
use Core\Payment\Contracts\RegisterProviderApplicationCommand;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Tests\Unit\Fakes\FakeGetProject;
use Core\Payment\Tests\Unit\Fakes\InMemoryProviderApplicationRepository;

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
    ));

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
    ));

    $useCase->register(new RegisterProviderApplicationCommand(
        projectId: 'project-1',
        providerCode: 'my-coolpay',
        publicKey: 'new-pub-key',
        privateKey: 'new-secret',
    ));

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
    ));
})->throws(RuntimeException::class);
