<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Listeners;

use Core\Events\Contracts\OutboxEventPublished;
use Core\Payment\Contracts\Events\PaymentStatusNotification;
use Core\Payment\Presentation\Jobs\DeliverPaymentCallbackJob;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Registered explicitly in `PaymentServiceProvider::boot()` (this
 * codebase's convention — no auto-discovery, mirrors
 * `Core\Webhook\Presentation\Listeners\FanOutRelayedEventListener`).
 *
 * Dispatches unconditionally for every `payment.status_changed` event;
 * `DeliverPaymentCallbackJob` itself no-ops when that payment has no
 * `callbackUrl` — cheaper to write, and the listener has no way to check
 * without the same repository read the job already does.
 */
final readonly class DeliverPaymentCallbackListener implements ShouldQueue
{
    public function handle(OutboxEventPublished $published): void
    {
        if ($published->event->type !== 'payment.status_changed') {
            return;
        }

        $notification = PaymentStatusNotification::fromPayload($published->event->payload);

        // ADR-0061: the callback carries the exact event the webhook plane
        // would — same id (receiver-side deduplication), same `data`.
        DeliverPaymentCallbackJob::dispatch($notification->paymentId, $published->event->id, $published->event->payload);
    }
}
