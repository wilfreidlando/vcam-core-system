<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * `limitOverride` lets a caller ask for a smaller batch than the
 * configured default (`config('payment.reconciliation.batch_size')`) —
 * used by the on-demand HTTP trigger to stay responsive; the scheduled
 * command omits it and gets the full configured batch.
 */
final readonly class ReconcileStuckPaymentsCommand
{
    public function __construct(
        public ?int $limitOverride = null,
    ) {}
}
