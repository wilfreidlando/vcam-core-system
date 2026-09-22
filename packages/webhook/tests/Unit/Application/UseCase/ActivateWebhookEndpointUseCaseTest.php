<?php

declare(strict_types=1);

use Core\Webhook\Application\UseCase\ActivateWebhookEndpointUseCase;
use Core\Webhook\Contracts\ActivateWebhookEndpointCommand;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookEndpointRepository;
use PHPUnit\Framework\Assert;

it('reactivates a deactivated endpoint owned by the calling project', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.test/hooks', 'whsec_test', ['*'], false));
    $useCase = new ActivateWebhookEndpointUseCase($endpoints);

    $useCase->activate(new ActivateWebhookEndpointCommand('project-1', 'endpoint-1'));

    $stored = $endpoints->find('endpoint-1');
    Assert::assertNotNull($stored);
    expect($stored->active)->toBeTrue();
});

it('rejects an unknown endpoint id', function (): void {
    $useCase = new ActivateWebhookEndpointUseCase(new InMemoryWebhookEndpointRepository);

    $useCase->activate(new ActivateWebhookEndpointCommand('project-1', 'unknown'));
})->throws(WebhookEndpointNotFound::class);

it('rejects an endpoint that belongs to another project, with the same exception as not-found', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.test/hooks', 'whsec_test', ['*'], false));
    $useCase = new ActivateWebhookEndpointUseCase($endpoints);

    $useCase->activate(new ActivateWebhookEndpointCommand('someone-elses-project', 'endpoint-1'));
})->throws(WebhookEndpointNotFound::class);
