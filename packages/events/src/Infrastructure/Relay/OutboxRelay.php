<?php

declare(strict_types=1);

namespace Core\Events\Infrastructure\Relay;

use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\OutboxEventPublished;
use Core\Events\Contracts\RelayOutboxEvents;
use Core\Events\Infrastructure\Persistence\Eloquent\EventOutboxRecord;
use Core\Support\Clock;

/**
 * The single physical reader of `event_outbox` (ADR-0040 §1). Every
 * consumer — `webhook` today, `notification`/`billing` later — subscribes
 * to `OutboxEventPublished` instead of polling the table itself, so the one
 * `published_at` column never has to mean "read by every consumer", only
 * "read by the relay".
 *
 * This is deliberately the sole class in the Core allowed to call the
 * `event()` helper directly (`NoDirectEventDispatchRule`'s namespace
 * exemption) — it is not bypassing the outbox, it is the mechanism that
 * publishes what the outbox already durably recorded.
 *
 * At-least-once, not exactly-once: a crash between `event()` and marking
 * a row published republishes it on the next run. Consumers are expected
 * to fan out idempotently (see `Core\Webhook`'s `UNIQUE(endpoint_id,
 * event_id)`), not to assume single delivery.
 */
final readonly class OutboxRelay implements RelayOutboxEvents
{
    public function __construct(
        private Clock $clock,
    ) {}

    public function relay(int $limit): int
    {
        // Eloquent forwards take()/limit() to the query builder via __call;
        // its `@method static` docblock is a Laravel-wide documentation
        // convention, not a real static method (see EloquentWalletBalanceRepository).
        // @phpstan-ignore staticMethod.dynamicCall
        $rows = EventOutboxRecord::query()
            ->where('published_at', null)
            ->oldest('created_at')
            ->take($limit)
            ->get();

        $relayed = 0;

        foreach ($rows as $row) {
            $event = new DomainEvent(
                id: $row->id,
                projectId: $row->project_id,
                type: $row->type,
                payload: $row->payload,
                correlationId: $row->correlation_id,
                occurredAt: $row->created_at,
            );

            event(new OutboxEventPublished($event));

            EventOutboxRecord::query()
                ->whereKey($row->id)
                ->update(['published_at' => $this->clock->now()]);

            $relayed++;
        }

        return $relayed;
    }
}
