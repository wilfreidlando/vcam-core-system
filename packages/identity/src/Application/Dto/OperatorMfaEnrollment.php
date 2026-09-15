<?php

declare(strict_types=1);

namespace Core\Identity\Application\Dto;

final readonly class OperatorMfaEnrollment
{
    public function __construct(
        public string $secret,
        public string $otpauthUri,
        public string $confirmChallengeToken,
    ) {}
}
