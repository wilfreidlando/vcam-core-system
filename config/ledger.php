<?php

declare(strict_types=1);

/**
 * Ledger defaults (ADR-0076 §6, ADR-0081 S-15).
 */
return [
    'adjustments' => [
        // At or above this amount (minor units), an operating correction
        // needs a second pair of eyes before it reaches the ledger: the
        // person who proposes it can never be the person who approves it.
        // The default (0) makes that second pair of eyes mandatory for
        // EVERY correction — which is what the ADR asks for.
        'approval_threshold_minor' => (int) env('LEDGER_ADJUSTMENT_APPROVAL_THRESHOLD_MINOR', 0),
    ],
];
