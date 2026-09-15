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
];
