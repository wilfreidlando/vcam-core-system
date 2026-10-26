<?php

declare(strict_types=1);

use Core\Support\Access\AccessScope;
use Core\Webhook\Application\UseCase\GetWebhookDeliveryDetailsUseCase;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookDeliveryAttemptRepository;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookDeliveryRepository;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookEndpointRepository;

function deliveryOf(string $id, string $projectId, string $eventId): WebhookDelivery
{
    return new WebhookDelivery(
        id: $id,
        endpointId: 'endpoint-'.$projectId,
        projectId: $projectId,
        eventId: $eventId,
        eventType: 'payment.status_changed',
        payload: [],
        status: WebhookDeliveryStatus::Delivered,
        attempts: 1,
        nextRetryAt: null,
        lastAttemptAt: new DateTimeImmutable('2026-09-15T10:00:00Z'),
        lastError: null,
        deliveredAt: new DateTimeImmutable('2026-09-15T10:00:05Z'),
    );
}

it('returns the delivery with its endpoint context', function (): void {
    $deliveries = new InMemoryWebhookDeliveryRepository;
    $deliveries->create(deliveryOf('d1', 'project-a', 'event-1'));

    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-project-a', 'project-a', 'https://example.test/hook', 'whsec_a', ['*'], true));

    $useCase = new GetWebhookDeliveryDetailsUseCase($deliveries, new InMemoryWebhookDeliveryAttemptRepository, $endpoints);

    $view = $useCase->forDelivery('d1', AccessScope::platform());

    assert($view !== null);

    expect($view->id)->toBe('d1')
        ->and($view->projectId)->toBe('project-a')
        ->and($view->endpointUrl)->toBe('https://example.test/hook');
});

it('reports a delivery of a project outside the accounts scope as not found', function (): void {
    // ADR-0069 §2 / S-04 — the answer must not confirm that the resource
    // exists: an out-of-scope delivery is "not found", never "forbidden".
    $deliveries = new InMemoryWebhookDeliveryRepository;
    $deliveries->create(deliveryOf('d1', 'project-a', 'event-1'));

    $useCase = new GetWebhookDeliveryDetailsUseCase(
        $deliveries,
        new InMemoryWebhookDeliveryAttemptRepository,
        new InMemoryWebhookEndpointRepository,
    );

    $view = $useCase->forDelivery('d1', AccessScope::forOrganization('org-1', ['project-b']));

    expect($view)->toBeNull();
});

it('returns the deliveries of an event, restricted to the accounts projects', function (): void {
    $deliveries = new InMemoryWebhookDeliveryRepository;
    $deliveries->create(deliveryOf('d1', 'project-a', 'event-1'));
    $deliveries->create(deliveryOf('d2', 'project-b', 'event-1'));

    $useCase = new GetWebhookDeliveryDetailsUseCase(
        $deliveries,
        new InMemoryWebhookDeliveryAttemptRepository,
        new InMemoryWebhookEndpointRepository,
    );

    $visible = $useCase->forEvents(['event-1'], AccessScope::forOrganization('org-1', ['project-a']));
    $all = $useCase->forEvents(['event-1'], AccessScope::platform());

    expect($visible)->toHaveCount(1)
        ->and($visible[0]->id)->toBe('d1')
        ->and($all)->toHaveCount(2);
});

it('returns nothing for an account without any membership', function (): void {
    $deliveries = new InMemoryWebhookDeliveryRepository;
    $deliveries->create(deliveryOf('d1', 'project-a', 'event-1'));

    $useCase = new GetWebhookDeliveryDetailsUseCase(
        $deliveries,
        new InMemoryWebhookDeliveryAttemptRepository,
        new InMemoryWebhookEndpointRepository,
    );

    expect($useCase->forDelivery('d1', AccessScope::none()))->toBeNull()
        ->and($useCase->forEvents(['event-1'], AccessScope::none()))->toBeEmpty();
});
