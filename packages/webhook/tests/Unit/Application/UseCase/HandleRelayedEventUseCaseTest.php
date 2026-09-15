<?php

declare(strict_types=1);

use Core\Events\Contracts\DomainEvent;
use Core\Webhook\Application\UseCase\HandleRelayedEventUseCase;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookDeliveryRepository;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookEndpointRepository;
use Core\Webhook\Tests\Unit\Fakes\SequentialIdentifier;
use PHPUnit\Framework\Assert;

function makeOutboxEvent(string $projectId = 'project-1', string $type = 'payment.status_changed'): DomainEvent
{
    return new DomainEvent('event-1', $projectId, $type, ['status' => 'succeeded'], null, new DateTimeImmutable('2026-09-15T10:00:00Z'));
}

it('creates a delivery for every active endpoint subscribed to the event type', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://a.test/hooks', 'whsec_a', ['payment.status_changed'], true));
    $endpoints->create(new WebhookEndpoint('endpoint-2', 'project-1', 'https://b.test/hooks', 'whsec_b', ['wallet.credited'], true));
    $deliveries = new InMemoryWebhookDeliveryRepository;
    $useCase = new HandleRelayedEventUseCase($endpoints, $deliveries, new SequentialIdentifier);

    $ids = $useCase->handle(makeOutboxEvent());

    expect($ids)->toHaveCount(1);
    $delivery = $deliveries->find($ids[0]);
    Assert::assertNotNull($delivery);
    expect($delivery->endpointId)->toBe('endpoint-1')
        ->and($delivery->eventId)->toBe('event-1')
        ->and($delivery->replayedFromId)->toBeNull();
});

it('ignores an inactive endpoint even if it would otherwise subscribe', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://a.test/hooks', 'whsec_a', ['*'], false));
    $deliveries = new InMemoryWebhookDeliveryRepository;
    $useCase = new HandleRelayedEventUseCase($endpoints, $deliveries, new SequentialIdentifier);

    $ids = $useCase->handle(makeOutboxEvent());

    expect($ids)->toBe([])
        ->and($deliveries->count())->toBe(0);
});

it('ignores an endpoint belonging to a different project', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'someone-elses-project', 'https://a.test/hooks', 'whsec_a', ['*'], true));
    $deliveries = new InMemoryWebhookDeliveryRepository;
    $useCase = new HandleRelayedEventUseCase($endpoints, $deliveries, new SequentialIdentifier);

    $ids = $useCase->handle(makeOutboxEvent(projectId: 'project-1'));

    expect($ids)->toBe([]);
});

it('is idempotent: fanning out the same event twice never creates a second delivery for the same endpoint', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://a.test/hooks', 'whsec_a', ['*'], true));
    $deliveries = new InMemoryWebhookDeliveryRepository;
    $useCase = new HandleRelayedEventUseCase($endpoints, $deliveries, new SequentialIdentifier);

    $first = $useCase->handle(makeOutboxEvent());
    $second = $useCase->handle(makeOutboxEvent());

    expect($first)->toHaveCount(1)
        ->and($second)->toBe([], 'the at-least-once relay must not duplicate a delivery for (endpoint, event)')
        ->and($deliveries->count())->toBe(1);
});
