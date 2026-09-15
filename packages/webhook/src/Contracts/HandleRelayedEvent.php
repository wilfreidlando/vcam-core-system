<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

use Core\Events\Contracts\DomainEvent;

/**
 * Fan-out: given one republished outbox event, create a `WebhookDelivery`
 * row for every active endpoint subscribed to it. Called from
 * `Presentation\Listeners\FanOutRelayedEventListener`, which is what
 * actually subscribes to `Core\Events\Contracts\OutboxEventPublished` —
 * this interface stays framework-free so the fan-out logic is testable
 * without Laravel's event bus.
 */
interface HandleRelayedEvent
{
    /**
     * @return list<string> ids of the `WebhookDelivery` rows created (or
     *                      already existing, per ADR-0040 §4) — the caller
     *                      dispatches one `DeliverWebhookJob` per id.
     */
    public function handle(DomainEvent $event): array;
}
