<?php

declare(strict_types=1);

namespace Core\Identity\Application\Dto;

final readonly class VerifyOperatorMfaCommand
{
    public function __construct(
        public string $challengeToken,
        public string $code,
    ) {}
}
