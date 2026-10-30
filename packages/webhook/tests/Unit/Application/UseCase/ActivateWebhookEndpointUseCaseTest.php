<?php

declare(strict_types=1);

use Core\Webhook\Application\UseCase\ActivateWebhookEndpointUseCase;
use Core\Webhook\Contracts\ActivateWebhookEndpointCommand;
use Core\Webhook\Contracts\TooManyWebhookEndpoints;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookEndpointRepository;
use Core\Webhook\Tests\Unit\Fakes\PassthroughUnitOfWork;
use PHPUnit\Framework\Assert;

it('reactivates a deactivated endpoint owned by the calling project', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.test/hooks', 'whsec_test', ['*'], false));
    $useCase = new ActivateWebhookEndpointUseCase($endpoints, new PassthroughUnitOfWork);

    $useCase->activate(new ActivateWebhookEndpointCommand('project-1', 'endpoint-1'));

    $stored = $endpoints->find('endpoint-1');
    Assert::assertNotNull($stored);
    expect($stored->active)->toBeTrue();
});

it('rejects an unknown endpoint id', function (): void {
    $useCase = new ActivateWebhookEndpointUseCase(new InMemoryWebhookEndpointRepository, new PassthroughUnitOfWork);

    $useCase->activate(new ActivateWebhookEndpointCommand('project-1', 'unknown'));
})->throws(WebhookEndpointNotFound::class);

it('rejects an endpoint that belongs to another project, with the same exception as not-found', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.test/hooks', 'whsec_test', ['*'], false));
    $useCase = new ActivateWebhookEndpointUseCase($endpoints, new PassthroughUnitOfWork);

    $useCase->activate(new ActivateWebhookEndpointCommand('someone-elses-project', 'endpoint-1'));
})->throws(WebhookEndpointNotFound::class);

/**
 * Audit 2026-10-29 #17 / G-12 — re-activating an endpoint adds one to the
 * project's active count. Without the ceiling on that path, the ceiling
 * would be a door any operator could walk around: deactivate ten
 * endpoints, then re-activate eleven.
 */
it('refuses to re-activate an endpoint past the project ceiling', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.test/hooks', 'whsec_test', ['*'], true));
    $endpoints->create(new WebhookEndpoint('endpoint-2', 'project-1', 'https://example.test/hooks', 'whsec_test', ['*'], false));

    $useCase = new ActivateWebhookEndpointUseCase($endpoints, new PassthroughUnitOfWork, maxActiveEndpointsPerProject: 1);

    $useCase->activate(new ActivateWebhookEndpointCommand('project-1', 'endpoint-2'));
})->throws(TooManyWebhookEndpoints::class);

it('re-activates an endpoint when the project is below the ceiling', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.test/hooks', 'whsec_test', ['*'], true));
    $endpoints->create(new WebhookEndpoint('endpoint-2', 'project-1', 'https://example.test/hooks', 'whsec_test', ['*'], false));

    $useCase = new ActivateWebhookEndpointUseCase($endpoints, new PassthroughUnitOfWork, maxActiveEndpointsPerProject: 2);

    $useCase->activate(new ActivateWebhookEndpointCommand('project-1', 'endpoint-2'));

    $stored = $endpoints->find('endpoint-2');
    Assert::assertNotNull($stored);
    expect($stored->active)->toBeTrue()
        ->and($endpoints->countActiveByProject('project-1'))->toBe(2);
});

it('never refuses an endpoint that is already active', function (): void {
    // Re-activating an active endpoint changes nothing: it is already
    // counted, so the ceiling must not refuse a no-op.
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.test/hooks', 'whsec_test', ['*'], true));

    $useCase = new ActivateWebhookEndpointUseCase($endpoints, new PassthroughUnitOfWork, maxActiveEndpointsPerProject: 1);

    $useCase->activate(new ActivateWebhookEndpointCommand('project-1', 'endpoint-1'));

    $stored = $endpoints->find('endpoint-1');
    Assert::assertNotNull($stored);
    expect($stored->active)->toBeTrue();
});
