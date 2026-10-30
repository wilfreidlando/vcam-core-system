<?php

declare(strict_types=1);

/**
 * Webhook delivery retry policy defaults (ADR-0040 §3). Values are seconds
 * unless noted; the schedule is `base * multiplier^(attempt-1)`, capped at
 * `max_delay_seconds`, up to `max_attempts` before a delivery moves to
 * `dead` (DLQ).
 */
return [
    'retry' => [
        'max_attempts' => (int) env('WEBHOOK_RETRY_MAX_ATTEMPTS', 8),
        'base_delay_seconds' => (int) env('WEBHOOK_RETRY_BASE_DELAY_SECONDS', 30),
        'multiplier' => (int) env('WEBHOOK_RETRY_MULTIPLIER', 3),
        'max_delay_seconds' => (int) env('WEBHOOK_RETRY_MAX_DELAY_SECONDS', 21_600),
    ],

    // Terminal deliveries older than this are pruned (audit 2026-10-03 §4.6).
    'retention_days' => (int) env('WEBHOOK_RETENTION_DAYS', 90),

    /**
     * Audit 2026-10-29 #17 / G-12 — the number of **active** endpoints a
     * project may hold at once.
     *
     * Every active endpoint receives every event of the project, so the
     * ceiling is per project — on the model of ADR-0082 §1's ten API keys
     * per project, which the audit names as the reference. Ten is the same
     * order of magnitude as that ceiling: a project rarely needs more than
     * a couple of receivers (one per consumer system, one per environment
     * of the integrator), and every endpoint beyond the ceiling widens the
     * exfiltration surface for no gain.
     *
     * A deactivated endpoint does not count (it receives nothing).
     */
    'max_active_endpoints_per_project' => (int) env('WEBHOOK_MAX_ACTIVE_ENDPOINTS_PER_PROJECT', 10),
];
