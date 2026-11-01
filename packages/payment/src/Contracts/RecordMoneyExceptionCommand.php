<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

final readonly class RecordMoneyExceptionCommand
{
    /**
     * @param string $kind a `money_exceptions.kind` value (`overpayment`, `late_payment`, `reconciliation_gap`, `invariant_violation`, …)
     * @param string $severity `critical` | `high` | `medium` | `low`
     * @param int $bookedAmountMinor what the caller already booked to `payment_exceptions` for it (0 = nothing)
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $projectId,
        public string $kind,
        public string $severity,
        public int $amountMinor,
        public string $currency,
        public int $bookedAmountMinor,
        public ?string $paymentId,
        public ?string $subjectRef,
        public string $summary,
        public array $details = [],
    ) {}
}
