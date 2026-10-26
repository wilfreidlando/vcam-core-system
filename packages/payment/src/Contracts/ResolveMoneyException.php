<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0077 §4 — settles an exception. Below the four-eyes threshold the
 * resolution is final at once and its ledger movement is booked in the
 * same transaction; at or above it, the exception waits for `approve()`
 * by a second person.
 *
 * ADR-0069 §2 — the scope is a REQUIRED parameter of every method: settling
 * an exception books money in a project's ledger, so the exception's
 * project must be one the caller may act on. An exception outside the
 * scope is reported as not found (S-04), never settled.
 */
interface ResolveMoneyException
{
    public function resolve(ResolveMoneyExceptionCommand $command, AccessScope $scope): MoneyExceptionView;

    public function approve(string $exceptionId, string $approverOperatorId, AccessScope $scope): MoneyExceptionView;

    public function reject(string $exceptionId, string $approverOperatorId, AccessScope $scope): MoneyExceptionView;
}
