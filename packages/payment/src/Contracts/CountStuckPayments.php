<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * A pure read — how many payments are currently stuck (non-terminal,
 * older than the configured threshold) — for a dashboard stat. Never
 * checks the provider, never mutates state; that's `ReconcileStuckPayments`'s
 * job. Added specifically so the admin console never needs to call
 * `ReconcileStuckPayments` just to populate a widget (review finding,
 * Lot 6 follow-up).
 */
interface CountStuckPayments
{
    public function count(): int;
}
