<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Support\Access\AccessScope;

/**
 * A pure read — the list of payments currently stuck (non-terminal, older
 * than the configured threshold) — for an operator to preview before
 * triggering reconciliation. Never checks the provider, never mutates
 * state; that's `ReconcileStuckPayments`'s job. Companion to
 * `CountStuckPayments`: that one answers "how many", this one answers
 * "which ones".
 */
interface ListStuckPayments
{
    /**
     * The scope is required (ADR-0069 §2): the preview only shows the
     * stuck payments of the projects the account may see.
     *
     * @return list<StuckPaymentView>
     */
    public function list(ListStuckPaymentsCommand $command, AccessScope $scope): array;
}
