<?php

declare(strict_types=1);

use Core\Webhook\Application\UseCase\ReplayWebhookDeliveryUseCase;
use Core\Webhook\Contracts\ReplayWebhookDeliveryCommand;
use Core\Webhook\Domain\Exception\WebhookDeliveryNotDead;
use Core\Webhook\Domain\Exception\WebhookDeliveryNotFound;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookDeliveryRepository;
use Core\Webhook\Tests\Unit\Fakes\SequentialIdentifier;
use PHPUnit\Framework\Assert;

function seedDeadDelivery(InMemoryWebhookDeliveryRepository $deliveries, string $id = 'delivery-1'): void
{
    $deliveries->create(new WebhookDelivery(
        id: $id,
        endpointId: 'endpoint-1',
        projectId: 'project-1',
        eventId: 'event-1',
        eventType: 'payment.status_changed',
        payload: ['status' => 'succeeded'],
        status: WebhookDeliveryStatus::Dead,
        attempts: 8,
        nextRetryAt: null,
        lastAttemptAt: new DateTimeImmutable('2026-09-15T10:00:00Z'),
        lastError: 'exhausted',
        deliveredAt: null,
    ));
}

it('creates a fresh delivery for the same (endpoint, event) pair, exempt from the fan-out uniqueness', function (): void {
    $deliveries = new InMemoryWebhookDeliveryRepository;
    seedDeadDelivery($deliveries);
    $useCase = new ReplayWebhookDeliveryUseCase($deliveries, new SequentialIdentifier);

    $view = $useCase->replay(new ReplayWebhookDeliveryCommand('delivery-1'));

    expect($view->newDeliveryId)->toBe('id-1');
    $fresh = $deliveries->find('id-1');
    Assert::assertNotNull($fresh);
    expect($fresh->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($fresh->attempts)->toBe(0)
        ->and($fresh->endpointId)->toBe('endpoint-1')
        ->and($fresh->eventId)->toBe('event-1')
        ->and($fresh->replayedFromId)->toBe('delivery-1');
});

it('rejects replaying an unknown delivery', function (): void {
    $useCase = new ReplayWebhookDeliveryUseCase(new InMemoryWebhookDeliveryRepository, new SequentialIdentifier);

    $useCase->replay(new ReplayWebhookDeliveryCommand('unknown'));
})->throws(WebhookDeliveryNotFound::class);

it('rejects replaying a delivery that is not dead', function (): void {
    $deliveries = new InMemoryWebhookDeliveryRepository;
    $deliveries->create(new WebhookDelivery(
        id: 'delivery-1',
        endpointId: 'endpoint-1',
        projectId: 'project-1',
        eventId: 'event-1',
        eventType: 'payment.status_changed',
        payload: [],
        status: WebhookDeliveryStatus::Pending,
        attempts: 1,
        nextRetryAt: new DateTimeImmutable('2026-09-15T10:05:00Z'),
        lastAttemptAt: null,
        lastError: null,
        deliveredAt: null,
    ));
    $useCase = new ReplayWebhookDeliveryUseCase($deliveries, new SequentialIdentifier);

    $useCase->replay(new ReplayWebhookDeliveryCommand('delivery-1'));
})->throws(WebhookDeliveryNotDead::class);
