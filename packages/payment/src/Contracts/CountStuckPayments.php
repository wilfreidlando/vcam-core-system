<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Support\Access\AccessScope;

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
    /**
     * The scope is required (ADR-0069 §2): only the payments of the
     * projects the console account may see are counted. The filter is
     * applied in the query — the payments of another tenant are never
     * counted, let alone materialised.
     */
    public function count(AccessScope $scope): int;
}
