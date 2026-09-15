<?php

declare(strict_types=1);

namespace Core\Identity\Application\Dto;

final readonly class LoginOperatorCommand
{
    public function __construct(
        public string $email,
        public string $password,
    ) {}
}
