<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Support\Access\AccessScope;

/**
 * Background reconciliation for missed webhooks (spec technique §10: "un
 * paiement resté processing au-delà d'un seuil déclenche une vérification
 * active"). Lives in `payment`, not `admin` (ADR-0044) — it needs
 * `PaymentRepository`, which is Domain and unreachable cross-module (G3c).
 * `admin` only ever calls this port.
 *
 * ADR-0069 §2 — the scope is a REQUIRED parameter: a reconciliation asks
 * the provider and may change a payment's status, credit a wallet and book
 * the ledger, so it only ever touches the payments of the projects in the
 * scope. The scheduler passes `AccessScope::platform()` explicitly; the
 * console passes the account's scope for `payments:reconcile:write`.
 */
interface ReconcileStuckPayments
{
    public function reconcile(ReconcileStuckPaymentsCommand $command, AccessScope $scope): ReconciliationSummary;
}
