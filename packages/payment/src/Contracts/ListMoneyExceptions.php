<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Support\Access\AccessScope;

interface ListMoneyExceptions
{
    /**
     * The scope is required (ADR-0069 §2) on every method: an exception
     * of a project the account may not see is not listed, not counted, and
     * not found.
     */
    public function list(ListMoneyExceptionsCommand $command, AccessScope $scope): MoneyExceptionPage;

    public function find(string $exceptionId, AccessScope $scope): ?MoneyExceptionView;

    /**
     * Open + awaiting-approval exceptions of the projects in the scope —
     * the console badge. Counted in the query: another tenant's exceptions
     * are never counted.
     */
    public function countUnresolved(AccessScope $scope): int;
}
