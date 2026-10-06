<?php

declare(strict_types=1);

use Core\Webhook\Application\UseCase\FailWebhookDeliveryUseCase;
use Core\Webhook\Application\UseCase\SweepStalledWebhookDeliveriesUseCase;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Tests\Unit\Fakes\FixedClock;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookDeliveryRepository;
use PHPUnit\Framework\Assert;
use Psr\Log\NullLogger;

/**
 * Audit 2026-10-03 §4.3 — a delivery never stays `pending` forever.
 */
function stalledDelivery(string $id, WebhookDeliveryStatus $status, ?DateTimeImmutable $nextRetryAt): WebhookDelivery
{
    return new WebhookDelivery(
        id: $id,
        endpointId: 'endpoint-1',
        projectId: 'project-1',
        eventId: 'event-'.$id,
        eventType: 'payment.status_changed',
        payload: [],
        status: $status,
        attempts: 1,
        nextRetryAt: $nextRetryAt,
        lastAttemptAt: null,
        lastError: null,
        deliveredAt: null,
    );
}

it('returns only pending deliveries overdue beyond the threshold', function (): void {
    $deliveries = new InMemoryWebhookDeliveryRepository;
    // FixedClock: 2026-09-15T10:00:00Z, threshold 15 minutes → before 09:45.
    $deliveries->create(stalledDelivery('overdue', WebhookDeliveryStatus::Pending, new DateTimeImmutable('2026-09-15T09:30:00Z')));
    $deliveries->create(stalledDelivery('recent', WebhookDeliveryStatus::Pending, new DateTimeImmutable('2026-09-15T09:55:00Z')));
    $deliveries->create(stalledDelivery('dead', WebhookDeliveryStatus::Dead, new DateTimeImmutable('2026-09-15T09:00:00Z')));

    $ids = (new SweepStalledWebhookDeliveriesUseCase($deliveries, new FixedClock))->sweep(15, 100);

    expect($ids)->toBe(['overdue']);
});

it('moves a pending delivery to dead when its job failed for good', function (): void {
    $deliveries = new InMemoryWebhookDeliveryRepository;
    $deliveries->create(stalledDelivery('d1', WebhookDeliveryStatus::Pending, null));

    (new FailWebhookDeliveryUseCase($deliveries, new FixedClock, new NullLogger))->fail('d1', 'Delivery job failed: boom');

    $delivery = $deliveries->find('d1');
    Assert::assertNotNull($delivery);
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Dead)
        ->and($delivery->lastError)->toBe('Delivery job failed: boom');
});

it('leaves an already delivered delivery untouched', function (): void {
    $deliveries = new InMemoryWebhookDeliveryRepository;
    $deliveries->create(stalledDelivery('d1', WebhookDeliveryStatus::Delivered, null));

    (new FailWebhookDeliveryUseCase($deliveries, new FixedClock, new NullLogger))->fail('d1', 'late failure');

    expect($deliveries->find('d1')?->status)->toBe(WebhookDeliveryStatus::Delivered);
});
