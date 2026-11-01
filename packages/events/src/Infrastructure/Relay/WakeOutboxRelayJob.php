<?php

declare(strict_types=1);

namespace Core\Events\Infrastructure\Relay;

use Core\Events\Contracts\RelayOutboxEvents;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * ADR-0090 §1 — queued right after a commit that wrote to `event_outbox`.
 *
 * It does not carry the event: it only runs one relay pass, which reads the
 * table. Several of these may run at once; the relay claims each row under a
 * `SKIP LOCKED` lock, so none publishes a row twice.
 */
final class WakeOutboxRelayJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function handle(RelayOutboxEvents $relay): void
    {
        $limit = config('events.relay.batch_limit');

        $relay->relay(is_int($limit) && $limit > 0 ? $limit : 100);
    }
}
