<?php

declare(strict_types=1);

/**
 * Payment reconciliation defaults (ADR-0044, spec technique §10: "un
 * paiement resté processing au-delà d'un seuil déclenche une vérification
 * active"). `stuck_after_minutes` is the age threshold on a non-terminal
 * payment's `updated_at` before it becomes a reconciliation candidate.
 */
return [
    'reconciliation' => [
        'stuck_after_minutes' => (int) env('PAYMENT_RECONCILIATION_STUCK_AFTER_MINUTES', 15),
        'batch_size' => (int) env('PAYMENT_RECONCILIATION_BATCH_SIZE', 100),
    ],
];
