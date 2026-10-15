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

    // ADR-0066 — the built-in payment simulator: a testing tool of the
    // staging deployment, never enabled in production. When on, a payment
    // whose project has no provider application goes to the simulator and
    // can be settled with POST /v1/payments/{id}/simulate.
    'simulator' => [
        'enabled' => (bool) env('PAYMENT_SIMULATOR_ENABLED', false),
    ],

    'reconciliation' => [
        // ADR-0067: checked every minute, a payment silent for 2 minutes is
        // asked to its provider; older than 24 hours, it is no longer asked
        // automatically (on-demand reconciliation still works at any age).
        'stuck_after_minutes' => (int) env('PAYMENT_RECONCILIATION_STUCK_AFTER_MINUTES', 2),
        'max_age_hours' => (int) env('PAYMENT_RECONCILIATION_MAX_AGE_HOURS', 24),
        'batch_size' => (int) env('PAYMENT_RECONCILIATION_BATCH_SIZE', 100),
    ],
];
