<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * ADR-0077 §3 — the one door through which any module deposits a money
 * exception (billing for an overpayment, the invariant checks, the
 * reconciliation). Returns the exception id, or `null` when the same
 * payment already has an exception of that kind (a replay).
 */
interface RecordMoneyException
{
    public function record(RecordMoneyExceptionCommand $command): ?string;
}
