<?php

declare(strict_types=1);

/**
 * Outbox relay defaults (ADR-0040 §1).
 */
return [
    'relay' => [
        'batch_limit' => (int) env('EVENTS_RELAY_BATCH_LIMIT', 100),
    ],
];
