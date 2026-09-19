<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

interface ListOperators
{
    public function list(ListOperatorsCommand $command): OperatorPage;
}
