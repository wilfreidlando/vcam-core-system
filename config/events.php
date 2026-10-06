<?php

declare(strict_types=1);

/**
 * Outbox relay defaults (ADR-0040 §1).
 */
return [
    'relay' => [
        'batch_limit' => (int) env('EVENTS_RELAY_BATCH_LIMIT', 100),
    ],

    // Published outbox rows older than this are pruned (audit 2026-10-03 §4.6).
    'retention_days' => (int) env('EVENTS_RETENTION_DAYS', 30),
];
