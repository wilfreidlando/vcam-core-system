<?php

declare(strict_types=1);

use Core\Webhook\Application\UseCase\ListWebhookEndpointsUseCase;
use Core\Webhook\Contracts\ListWebhookEndpointsCommand;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookEndpointRepository;

it('lists both active and inactive endpoints for the project', function (): void {
    // Review finding: this used to query active-only, so a deactivated
    // endpoint simply vanished from the listing instead of showing as
    // inactive — making it unreachable to reactivate or edit.
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-active', 'project-1', 'https://example.test/a', 'whsec_a', ['*'], true));
    $endpoints->create(new WebhookEndpoint('endpoint-inactive', 'project-1', 'https://example.test/b', 'whsec_b', ['*'], false));
    $useCase = new ListWebhookEndpointsUseCase($endpoints);

    $views = $useCase->list(new ListWebhookEndpointsCommand('project-1'));

    expect($views)->toHaveCount(2);
    $activeFlags = array_map(static fn ($view): bool => $view->active, $views);
    expect($activeFlags)->toContain(true)
        ->and($activeFlags)->toContain(false);
});

it('never lists another projects endpoints', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.test/a', 'whsec_a', ['*'], true));
    $endpoints->create(new WebhookEndpoint('endpoint-2', 'project-2', 'https://example.test/b', 'whsec_b', ['*'], true));
    $useCase = new ListWebhookEndpointsUseCase($endpoints);

    $views = $useCase->list(new ListWebhookEndpointsCommand('project-1'));

    expect($views)->toHaveCount(1)
        ->and($views[0]->id)->toBe('endpoint-1');
});
