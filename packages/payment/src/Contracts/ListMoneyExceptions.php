<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

interface ListMoneyExceptions
{
    public function list(ListMoneyExceptionsCommand $command): MoneyExceptionPage;

    public function find(string $exceptionId): ?MoneyExceptionView;

    /**
     * Open + awaiting-approval exceptions — the console badge.
     *
     * @param list<string>|null $projectIds
     */
    public function countUnresolved(?array $projectIds = null): int;
}
