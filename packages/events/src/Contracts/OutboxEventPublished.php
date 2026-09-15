<?php

declare(strict_types=1);

namespace Core\Events\Contracts;

/**
 * The internal republication of one outbox row, dispatched by
 * `Core\Events\Infrastructure\Relay\OutboxRelay` — the only place in the
 * Core allowed to call the `event()` helper directly (see
 * `tools/PhpStan/Rules/NoDirectEventDispatchRule.php`).
 *
 * Consumers (the `webhook` module today, `notification`/`billing` later)
 * subscribe to this type, never to the outbox table itself: the outbox has
 * a single physical reader (ADR-0040 §1), and this event is the fan-out
 * point for everyone downstream. A consumer that needs the delivery to
 * survive a worker restart implements `ShouldQueue` on its listener.
 */
final readonly class OutboxEventPublished
{
    public function __construct(
        public DomainEvent $event,
    ) {}
}
