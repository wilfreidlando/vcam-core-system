<?php

declare(strict_types=1);

namespace Core\Identity\Application\Dto;

final readonly class EnrollOperatorMfaCommand
{
    public function __construct(
        public string $challengeToken,
    ) {}
}
