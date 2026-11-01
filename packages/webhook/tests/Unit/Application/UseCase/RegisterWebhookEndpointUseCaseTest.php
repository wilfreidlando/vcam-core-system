<?php

declare(strict_types=1);

use Core\Webhook\Application\UseCase\RegisterWebhookEndpointUseCase;
use Core\Webhook\Contracts\RegisterWebhookEndpointCommand;
use Core\Webhook\Contracts\TooManyWebhookEndpoints;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Tests\Unit\Fakes\FixedWebhookSecretGenerator;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookEndpointRepository;
use Core\Webhook\Tests\Unit\Fakes\PassthroughUnitOfWork;
use Core\Webhook\Tests\Unit\Fakes\SequentialIdentifier;
use PHPUnit\Framework\Assert;

it('registers an endpoint and returns the plaintext secret once', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $useCase = new RegisterWebhookEndpointUseCase($endpoints, new FixedWebhookSecretGenerator('whsec_abc'), new SequentialIdentifier, new PassthroughUnitOfWork);

    $view = $useCase->register(new RegisterWebhookEndpointCommand('project-1', 'https://example.test/hooks', ['payment.status_changed']));

    expect($view->id)->toBe('id-1')
        ->and($view->url)->toBe('https://example.test/hooks')
        ->and($view->events)->toBe(['payment.status_changed'])
        ->and($view->secret)->toBe('whsec_abc');

    $stored = $endpoints->find('id-1');
    Assert::assertNotNull($stored);
    expect($stored->secret)->toBe('whsec_abc')
        ->and($stored->active)->toBeTrue();
});

/**
 * Audit 2026-10-29 #17 / G-12 — a project holds a bounded number of
 * active endpoints: every active one receives every event of the project,
 * so an unbounded number of them is an unbounded exfiltration channel.
 * The ceiling is per project, on the model of ADR-0082 §1's ten API keys.
 */
it('refuses to register an active endpoint past the project ceiling', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $useCase = new RegisterWebhookEndpointUseCase(
        $endpoints,
        new FixedWebhookSecretGenerator('whsec_abc'),
        new SequentialIdentifier,
        new PassthroughUnitOfWork,
        maxActiveEndpointsPerProject: 2,
    );

    $useCase->register(new RegisterWebhookEndpointCommand('project-1', 'https://example.test/hooks', ['*']));
    $useCase->register(new RegisterWebhookEndpointCommand('project-1', 'https://example.test/hooks', ['*']));

    $useCase->register(new RegisterWebhookEndpointCommand('project-1', 'https://example.test/hooks', ['*']));
})->throws(TooManyWebhookEndpoints::class);

it('leaves a project below the ceiling unaffected', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $useCase = new RegisterWebhookEndpointUseCase(
        $endpoints,
        new FixedWebhookSecretGenerator('whsec_abc'),
        new SequentialIdentifier,
        new PassthroughUnitOfWork,
        maxActiveEndpointsPerProject: 3,
    );

    $first = $useCase->register(new RegisterWebhookEndpointCommand('project-1', 'https://example.test/hooks', ['*']));
    $second = $useCase->register(new RegisterWebhookEndpointCommand('project-1', 'https://example.test/hooks', ['*']));

    expect($first->id)->toBe('id-1')
        ->and($second->id)->toBe('id-2')
        ->and($endpoints->countActiveByProject('project-1'))->toBe(2);
});

it('does not count a deactivated endpoint against the ceiling', function (): void {
    // A deactivated endpoint receives nothing: it must not block the
    // registration of a live one.
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.test/hooks', 'whsec_old', ['*'], false));

    $useCase = new RegisterWebhookEndpointUseCase(
        $endpoints,
        new FixedWebhookSecretGenerator('whsec_abc'),
        new SequentialIdentifier,
        new PassthroughUnitOfWork,
        maxActiveEndpointsPerProject: 1,
    );

    $view = $useCase->register(new RegisterWebhookEndpointCommand('project-1', 'https://example.test/hooks', ['*']));

    expect($view->id)->toBe('id-1')
        ->and($endpoints->countActiveByProject('project-1'))->toBe(1);
});

it('counts each project on its own', function (): void {
    // The ceiling is per project: another tenant's endpoints are neither
    // counted against this project nor blocked by it.
    $endpoints = new InMemoryWebhookEndpointRepository;
    $useCase = new RegisterWebhookEndpointUseCase(
        $endpoints,
        new FixedWebhookSecretGenerator('whsec_abc'),
        new SequentialIdentifier,
        new PassthroughUnitOfWork,
        maxActiveEndpointsPerProject: 1,
    );

    $useCase->register(new RegisterWebhookEndpointCommand('project-1', 'https://example.test/hooks', ['*']));
    $view = $useCase->register(new RegisterWebhookEndpointCommand('project-2', 'https://example.test/hooks', ['*']));

    expect($view->id)->toBe('id-2')
        ->and($endpoints->countActiveByProject('project-1'))->toBe(1)
        ->and($endpoints->countActiveByProject('project-2'))->toBe(1);
});
