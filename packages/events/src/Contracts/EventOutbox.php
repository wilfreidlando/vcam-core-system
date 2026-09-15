<?php

declare(strict_types=1);

namespace Core\Events\Contracts;

/**
 * The module's entire published surface: append an event, inside the
 * caller's own database transaction. There is deliberately no "publish now"
 * or "read pending" method here — that belongs to the relay (Lot 4), which
 * does not exist yet and is a different concern (delivery, not recording).
 */
interface EventOutbox
{
    public function append(DomainEvent $event): void;
}
