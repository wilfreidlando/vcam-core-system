<?php

declare(strict_types=1);

use Core\Webhook\Application\UseCase\UpdateWebhookEndpointUseCase;
use Core\Webhook\Contracts\UpdateWebhookEndpointCommand;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookEndpointRepository;
use PHPUnit\Framework\Assert;

it('changes the url and events of an endpoint owned by the calling project', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.test/hooks', 'whsec_test', ['payment.status_changed'], true));
    $useCase = new UpdateWebhookEndpointUseCase($endpoints);

    $view = $useCase->update(new UpdateWebhookEndpointCommand(
        projectId: 'project-1',
        endpointId: 'endpoint-1',
        url: 'https://example.test/new-hooks',
        events: ['wallet.credited', 'wallet.debited'],
    ));

    expect($view->url)->toBe('https://example.test/new-hooks')
        ->and($view->events)->toBe(['wallet.credited', 'wallet.debited'])
        // Active state is untouched by update() — only activate()/deactivate() change it.
        ->and($view->active)->toBeTrue();

    $stored = $endpoints->find('endpoint-1');
    Assert::assertNotNull($stored);
    expect($stored->url)->toBe('https://example.test/new-hooks')
        ->and($stored->secret)->toBe('whsec_test', 'the signing secret is never touched by update()');
});

it('rejects an unknown endpoint id', function (): void {
    $useCase = new UpdateWebhookEndpointUseCase(new InMemoryWebhookEndpointRepository);

    $useCase->update(new UpdateWebhookEndpointCommand('project-1', 'unknown', 'https://example.test/hooks', ['*']));
})->throws(WebhookEndpointNotFound::class);

it('rejects an endpoint that belongs to another project, with the same exception as not-found', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.test/hooks', 'whsec_test', ['*'], true));
    $useCase = new UpdateWebhookEndpointUseCase($endpoints);

    $useCase->update(new UpdateWebhookEndpointCommand('someone-elses-project', 'endpoint-1', 'https://example.test/new-hooks', ['*']));
})->throws(WebhookEndpointNotFound::class);
