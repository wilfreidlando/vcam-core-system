<?php

declare(strict_types=1);

use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\OutboxEventPublished;
use Core\Webhook\Presentation\Listeners\FanOutRelayedEventListener;
use Core\Webhook\Tests\Unit\Fakes\SpyHandleRelayedEvent;

function skippableOutboxEvent(bool $skip): DomainEvent
{
    return new DomainEvent(
        'event-1',
        'project-1',
        'payment.status_changed',
        ['status' => 'succeeded'],
        null,
        new DateTimeImmutable('2026-09-15T10:00:00Z'),
        skipWebhookEndpointFanOut: $skip,
    );
}

it('skips the generic fan-out when the event carries the skip hint', function (): void {
    $spy = new SpyHandleRelayedEvent;
    $listener = new FanOutRelayedEventListener($spy);

    $listener->handle(new OutboxEventPublished(skippableOutboxEvent(skip: true)));

    expect($spy->calls)->toBe(0);
});

it('fans out as usual when the event does not carry the skip hint', function (): void {
    $spy = new SpyHandleRelayedEvent;
    $listener = new FanOutRelayedEventListener($spy);

    $listener->handle(new OutboxEventPublished(skippableOutboxEvent(skip: false)));

    expect($spy->calls)->toBe(1);
});
