<?php

declare(strict_types=1);

namespace Core\Events\Infrastructure\Persistence\Eloquent;

use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Events\Infrastructure\Relay\WakeOutboxRelayJob;
use Core\Support\Observability\TraceContext;
use Illuminate\Support\Facades\DB;

final class EloquentEventOutbox implements EventOutbox
{
    public function append(DomainEvent $event): void
    {
        EventOutboxRecord::query()->create([
            'id' => $event->id,
            'project_id' => $event->projectId,
            'type' => $event->type,
            'payload' => $event->payload,
            // ADR-0059: a publisher that did not pass a correlation id
            // (most use cases never see one) still gets the request's —
            // the thread from the API call to the webhook delivery must
            // never break at the outbox.
            'correlation_id' => $event->correlationId ?? TraceContext::correlationId(),
            'trace_id' => TraceContext::traceId(),
            'published_at' => null,
            'created_at' => $event->occurredAt,
            'skip_webhook_endpoint_fan_out' => $event->skipWebhookEndpointFanOut,
        ]);

        // ADR-0090 §1: the relay is woken **after the commit** that wrote the row,
        // so a webhook leaves in seconds rather than at the next minute. The
        // minute sweep stays as the net: a lost wake-up costs a minute, not an event.
        if (config('events.relay.on_commit') === true) {
            DB::afterCommit(static fn () => WakeOutboxRelayJob::dispatch());
        }
    }
}
