<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Port;

use DateTimeImmutable;

/**
 * A stateless, tamper-proof, self-expiring token carrying `{operatorId,
 * purpose}` between steps of the login/MFA-enrollment flow — no new table
 * for "pending login" state, the same reasoning `ApiKeyToken` applies to
 * the machine-identity plane's bearer tokens.
 */
interface LoginChallengeCodec
{
    public function issue(string $operatorId, string $purpose, DateTimeImmutable $expiresAt): string;

    /**
     * @return string|null the operator id, or null if the token is
     *                     invalid, expired, or was issued for a different purpose
     */
    public function verify(string $token, string $expectedPurpose): ?string;
}
