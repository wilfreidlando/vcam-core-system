<?php

declare(strict_types=1);

namespace Core\Events\Contracts;

/**
 * ADR-0059 — how far behind the relay is. An outbox that stops draining
 * means no webhook, no payment callback, nothing downstream: the single
 * most important health signal of the event plane.
 */
interface OutboxHealth
{
    public function snapshot(): OutboxHealthView;
}
