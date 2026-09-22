<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use RuntimeException;

/**
 * On-demand reconciliation for exactly one, operator-selected payment —
 * distinct from `ReconcileStuckPayments`, which processes a batch the Core
 * itself picks (non-terminal, untouched past a configured age). This port
 * exists for the case `ReconcileStuckPayments` cannot serve: an operator
 * looking at one specific transaction (stuck or not — checking a terminal
 * payment against its provider is harmless, it will simply report no
 * change) who wants to check it against its provider right now, not wait
 * for the age threshold or the next scheduled batch.
 *
 * Converges on the exact same `PaymentGateway::checkStatus()` +
 * `PaymentStatusChangeHandler::handle()` path `ReconcileStuckPayments`
 * uses — no separate status-transition logic.
 */
interface ReconcilePayment
{
    /**
     * @throws RuntimeException if no payment exists with that id.
     */
    public function reconcile(ReconcilePaymentCommand $command): PaymentReconciliationResult;
}
