<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * Background reconciliation for missed webhooks (spec technique §10: "un
 * paiement resté processing au-delà d'un seuil déclenche une vérification
 * active"). Lives in `payment`, not `admin` (ADR-0044) — it needs
 * `PaymentRepository`, which is Domain and unreachable cross-module (G3c).
 * `admin` only ever calls this port.
 */
interface ReconcileStuckPayments
{
    public function reconcile(ReconcileStuckPaymentsCommand $command): ReconciliationSummary;
}
