<?php

declare(strict_types=1);

use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;

function makeDelivery(): WebhookDelivery
{
    return new WebhookDelivery(
        id: 'delivery-1',
        endpointId: 'endpoint-1',
        projectId: 'project-1',
        eventId: 'event-1',
        eventType: 'payment.status_changed',
        payload: ['status' => 'succeeded'],
        status: WebhookDeliveryStatus::Pending,
        attempts: 0,
        nextRetryAt: null,
        lastAttemptAt: null,
        lastError: null,
        deliveredAt: null,
    );
}

it('recordSuccess marks the delivery delivered and stamps deliveredAt', function (): void {
    $delivery = makeDelivery();
    $at = new DateTimeImmutable('2026-09-15T10:05:00Z');

    $updated = $delivery->recordSuccess($at, 200, '{"ok":true}');

    expect($updated)->not->toBe($delivery)
        ->and($updated->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($updated->attempts)->toBe(1)
        ->and($updated->deliveredAt)->toBe($at)
        ->and($updated->nextRetryAt)->toBeNull()
        ->and($updated->lastResponseStatus)->toBe(200)
        ->and($updated->lastResponseBody)->toBe('{"ok":true}')
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending, 'the original instance is untouched');
});

it('recordFailure with a next retry time stays pending', function (): void {
    $delivery = makeDelivery();
    $at = new DateTimeImmutable('2026-09-15T10:05:00Z');
    $retryAt = new DateTimeImmutable('2026-09-15T10:05:30Z');

    $updated = $delivery->recordFailure('connection timed out', $at, $retryAt, null, null);

    expect($updated->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($updated->attempts)->toBe(1)
        ->and($updated->nextRetryAt)->toBe($retryAt)
        ->and($updated->lastError)->toBe('connection timed out')
        ->and($updated->lastResponseStatus)->toBeNull()
        ->and($updated->deliveredAt)->toBeNull();
});

it('recordFailure with no next retry time moves to dead (DLQ)', function (): void {
    $delivery = makeDelivery();
    $at = new DateTimeImmutable('2026-09-15T10:05:00Z');

    $updated = $delivery->recordFailure('exhausted', $at, null, 500, 'internal error');

    expect($updated->status)->toBe(WebhookDeliveryStatus::Dead)
        ->and($updated->nextRetryAt)->toBeNull()
        ->and($updated->lastResponseStatus)->toBe(500)
        ->and($updated->lastResponseBody)->toBe('internal error');
});

it('preserves replayedFromId across transitions', function (): void {
    $delivery = new WebhookDelivery(
        id: 'delivery-2',
        endpointId: 'endpoint-1',
        projectId: 'project-1',
        eventId: 'event-1',
        eventType: 'payment.status_changed',
        payload: [],
        status: WebhookDeliveryStatus::Pending,
        attempts: 0,
        nextRetryAt: null,
        lastAttemptAt: null,
        lastError: null,
        deliveredAt: null,
        replayedFromId: 'delivery-1',
    );

    $updated = $delivery->recordSuccess(new DateTimeImmutable('2026-09-15T10:05:00Z'), 200, null);

    expect($updated->replayedFromId)->toBe('delivery-1');
});
