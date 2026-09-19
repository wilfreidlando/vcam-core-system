<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

final readonly class RevokeOperatorCommand
{
    public function __construct(
        public string $operatorId,
    ) {}
}
