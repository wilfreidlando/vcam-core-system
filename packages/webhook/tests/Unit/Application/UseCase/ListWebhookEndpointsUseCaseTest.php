<?php

declare(strict_types=1);

use Core\Support\Access\AccessScope;
use Core\Webhook\Application\UseCase\ListWebhookEndpointsUseCase;
use Core\Webhook\Contracts\ListWebhookEndpointsCommand;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Tests\Unit\Fakes\FixedClock;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookEndpointRepository;

it('lists both active and inactive endpoints for the project', function (): void {
    // Review finding: this used to query active-only, so a deactivated
    // endpoint simply vanished from the listing instead of showing as
    // inactive — making it unreachable to reactivate or edit.
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-active', 'project-1', 'https://example.test/a', 'whsec_a', ['*'], true));
    $endpoints->create(new WebhookEndpoint('endpoint-inactive', 'project-1', 'https://example.test/b', 'whsec_b', ['*'], false));
    $useCase = new ListWebhookEndpointsUseCase($endpoints, new FixedClock);

    $views = $useCase->list(new ListWebhookEndpointsCommand('project-1'), AccessScope::platform());

    expect($views)->toHaveCount(2);
    $activeFlags = array_map(static fn ($view): bool => $view->active, $views);
    expect($activeFlags)->toContain(true)
        ->and($activeFlags)->toContain(false);
});

it('never lists another projects endpoints', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.test/a', 'whsec_a', ['*'], true));
    $endpoints->create(new WebhookEndpoint('endpoint-2', 'project-2', 'https://example.test/b', 'whsec_b', ['*'], true));
    $useCase = new ListWebhookEndpointsUseCase($endpoints, new FixedClock);

    $views = $useCase->list(new ListWebhookEndpointsCommand('project-1'), AccessScope::platform());

    expect($views)->toHaveCount(1)
        ->and($views[0]->id)->toBe('endpoint-1');
});

it('lists nothing when the project is outside the accounts scope', function (): void {
    // ADR-0069 §2 — the scope is a required parameter of the port: a
    // project the console account may not see has no endpoints listed.
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.test/a', 'whsec_a', ['*'], true));
    $useCase = new ListWebhookEndpointsUseCase($endpoints, new FixedClock);

    $views = $useCase->list(
        new ListWebhookEndpointsCommand('project-1'),
        AccessScope::forOrganization('org-1', ['project-2']),
    );

    expect($views)->toBeEmpty();
});

it('lists the endpoints of a project the accounts scope allows', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.test/a', 'whsec_a', ['*'], true));
    $endpoints->create(new WebhookEndpoint('endpoint-2', 'project-2', 'https://example.test/b', 'whsec_b', ['*'], true));
    $useCase = new ListWebhookEndpointsUseCase($endpoints, new FixedClock);

    $views = $useCase->list(
        new ListWebhookEndpointsCommand('project-1'),
        AccessScope::forOrganization('org-1', ['project-1', 'project-2']),
    );

    expect($views)->toHaveCount(1)
        ->and($views[0]->id)->toBe('endpoint-1');
});

it('lists nothing for an account without any membership', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.test/a', 'whsec_a', ['*'], true));
    $useCase = new ListWebhookEndpointsUseCase($endpoints, new FixedClock);

    $views = $useCase->list(new ListWebhookEndpointsCommand('project-1'), AccessScope::none());

    expect($views)->toBeEmpty();
});
