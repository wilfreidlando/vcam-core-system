<?php

declare(strict_types=1);

namespace Core\Webhook\Presentation\Listeners;

use Core\Events\Contracts\OutboxEventPublished;
use Core\Webhook\Contracts\DeliverWebhookCommand;
use Core\Webhook\Contracts\HandleRelayedEvent;
use Core\Webhook\Presentation\Jobs\DeliverWebhookJob;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Registered explicitly in `WebhookServiceProvider::boot()` (this
 * codebase's convention — no auto-discovery). `ShouldQueue` means the fan-out
 * itself (endpoint matching, `WebhookDelivery` creation) runs on Horizon,
 * not inside the process running `events:relay` (ADR-0040 §2).
 */
final readonly class FanOutRelayedEventListener implements ShouldQueue
{
    public function __construct(
        private HandleRelayedEvent $handleRelayedEvent,
    ) {}

    public function handle(OutboxEventPublished $published): void
    {
        // Publisher-set hint (see `DomainEvent::$skipWebhookEndpointFanOut`'s
        // own docblock): this event already has its own dedicated,
        // point-to-point delivery arranged — e.g. a payment's own
        // `callback_url` — so the generic Project-wide endpoint fan-out
        // this listener does is skipped for it. User-directed design:
        // avoid a payment notifying both its own callback_url and every
        // registered webhook endpoint for the same status change.
        if ($published->event->skipWebhookEndpointFanOut) {
            return;
        }

        $deliveryIds = $this->handleRelayedEvent->handle($published->event);

        foreach ($deliveryIds as $deliveryId) {
            DeliverWebhookJob::dispatch(new DeliverWebhookCommand($deliveryId));
        }
    }
}
