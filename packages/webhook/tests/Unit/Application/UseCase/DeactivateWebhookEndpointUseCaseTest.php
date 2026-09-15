<?php

declare(strict_types=1);

use Core\Webhook\Application\UseCase\DeactivateWebhookEndpointUseCase;
use Core\Webhook\Contracts\DeactivateWebhookEndpointCommand;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookEndpointRepository;
use PHPUnit\Framework\Assert;

function seedEndpoint(InMemoryWebhookEndpointRepository $repo, string $id = 'endpoint-1', string $projectId = 'project-1'): void
{
    $repo->create(new WebhookEndpoint($id, $projectId, 'https://example.test/hooks', 'whsec_test', ['*'], true));
}

it('deactivates an endpoint owned by the calling project', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    seedEndpoint($endpoints);
    $useCase = new DeactivateWebhookEndpointUseCase($endpoints);

    $useCase->deactivate(new DeactivateWebhookEndpointCommand('project-1', 'endpoint-1'));

    $stored = $endpoints->find('endpoint-1');
    Assert::assertNotNull($stored);
    expect($stored->active)->toBeFalse();
});

it('rejects an unknown endpoint id', function (): void {
    $useCase = new DeactivateWebhookEndpointUseCase(new InMemoryWebhookEndpointRepository);

    $useCase->deactivate(new DeactivateWebhookEndpointCommand('project-1', 'unknown'));
})->throws(WebhookEndpointNotFound::class);

it('rejects an endpoint that belongs to another project, with the same exception as not-found', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    seedEndpoint($endpoints, 'endpoint-1', 'project-1');
    $useCase = new DeactivateWebhookEndpointUseCase($endpoints);

    $useCase->deactivate(new DeactivateWebhookEndpointCommand('someone-elses-project', 'endpoint-1'));
})->throws(WebhookEndpointNotFound::class);
