<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0074 §2 — the only door through which a counter moves.
 *
 * Atomic, idempotent on `(project, meter, idempotency_key)`, and refusing
 * **before** the effect: a refused call leaves no trace and can be
 * replayed with the same key once the plan changes. When called inside a
 * transaction (the notification cascade), it joins it.
 */
interface ConsumeUsage
{
    public function consume(ConsumeUsageCommand $command): UsageDecision;
}
