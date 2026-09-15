<?php

declare(strict_types=1);

namespace Core\Identity\Application\Dto;

/**
 * `purpose` tells the caller which endpoint to call next:
 * `mfa_enrollment` (first login, no MFA yet — call `mfa/enroll`) or `mfa`
 * (MFA already enabled — call `mfa/verify` directly).
 */
final readonly class OperatorLoginChallenge
{
    public function __construct(
        public string $challengeToken,
        public string $purpose,
    ) {}
}
