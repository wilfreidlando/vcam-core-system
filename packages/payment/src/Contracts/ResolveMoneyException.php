<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * ADR-0077 §4 — settles an exception. Below the four-eyes threshold the
 * resolution is final at once and its ledger movement is booked in the
 * same transaction; at or above it, the exception waits for `approve()`
 * by a second person.
 */
interface ResolveMoneyException
{
    public function resolve(ResolveMoneyExceptionCommand $command): MoneyExceptionView;

    public function approve(string $exceptionId, string $approverOperatorId): MoneyExceptionView;

    public function reject(string $exceptionId, string $approverOperatorId): MoneyExceptionView;
}
