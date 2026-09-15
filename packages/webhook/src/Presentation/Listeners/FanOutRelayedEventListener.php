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
        $deliveryIds = $this->handleRelayedEvent->handle($published->event);

        foreach ($deliveryIds as $deliveryId) {
            DeliverWebhookJob::dispatch(new DeliverWebhookCommand($deliveryId));
        }
    }
}
