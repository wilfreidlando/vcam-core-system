<?php

declare(strict_types=1);

namespace Core\Identity\Application\Dto;

use Core\Identity\OperatorRole;

final readonly class CreateOperatorCommand
{
    public function __construct(
        public string $email,
        public string $name,
        public OperatorRole $role,
    ) {}
}
