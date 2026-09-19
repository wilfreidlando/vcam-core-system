<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

interface CreateOperator
{
    /**
     * @throws RuntimeException if `$command->role` isn't a recognised
     *                          `OperatorRole` value. The concrete exception type
     *                          lives in the Domain layer; Contracts must not
     *                          reference it (G3c).
     */
    public function create(CreateOperatorCommand $command, string $actor, ?string $correlationId): CreatedOperatorView;
}
