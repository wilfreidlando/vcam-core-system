<?php

declare(strict_types=1);

/**
 * Payment reconciliation defaults (ADR-0044, spec technique §10: "un
 * paiement resté processing au-delà d'un seuil déclenche une vérification
 * active"). `stuck_after_minutes` is the age threshold on a non-terminal
 * payment's `updated_at` before it becomes a reconciliation candidate.
 */
return [
    // ADR-0059 / decision D5: retention of each payment's "Parcours"
    // (journey details include customer phone numbers and names).
    'timeline_retention_days' => (int) env('PAYMENT_TIMELINE_RETENTION_DAYS', 90),

    'reconciliation' => [
        'stuck_after_minutes' => (int) env('PAYMENT_RECONCILIATION_STUCK_AFTER_MINUTES', 15),
        'batch_size' => (int) env('PAYMENT_RECONCILIATION_BATCH_SIZE', 100),
    ],
];
