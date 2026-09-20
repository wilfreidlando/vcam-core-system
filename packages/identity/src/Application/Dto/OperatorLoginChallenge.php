<?php

declare(strict_types=1);

namespace Core\Identity\Application\Dto;

/**
 * `purpose` tells the caller which endpoint to call next:
 * `mfa_enrollment` (first login, no MFA yet — call `mfa/enroll`) or `mfa`
 * (MFA already enabled — call `mfa/verify` directly).
 *
 * `operatorId` is always populated — the controller uses it to issue a
 * Sanctum session token directly when MFA has been turned off via
 * `IDENTITY_DISABLE_MFA=true` (the password has already been verified at
 * this point; re-running it would just duplicate work and risks drift
 * between the two verification paths).
 */
final readonly class OperatorLoginChallenge
{
    public function __construct(
        public string $purpose,
        public string $challengeToken,
        public string $operatorId,
    ) {}
}
