<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Security;

use Core\Identity\Domain\Port\TotpProvider;
use PragmaRX\Google2FA\Google2FA;

final class Google2faTotpProvider implements TotpProvider
{
    private const string ISSUER = 'Enterprise Core System';

    public function __construct(
        private readonly Google2FA $google2fa,
    ) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function verify(string $secret, string $code, ?int $afterTimestep = null): int|false
    {
        if ($afterTimestep === null) {
            // No prior timestep to compare against (first-ever
            // verification for this operator) — `verifyKey()` alone
            // returns true/false, not a timestep, so resolve one
            // ourselves via `getTimestamp()` for the caller to persist.
            if ($this->google2fa->verifyKey($secret, $code) !== true) {
                return false;
            }

            return $this->google2fa->getTimestamp();
        }

        $matchedTimestep = $this->google2fa->verifyKeyNewer($secret, $code, $afterTimestep);

        return is_int($matchedTimestep) ? $matchedTimestep : false;
    }

    public function buildOtpauthUri(string $secret, string $email): string
    {
        return $this->google2fa->getQRCodeUrl(self::ISSUER, $email, $secret);
    }

    public function currentCode(string $secret): string
    {
        // The library's own public method already does exactly this
        // (current 30s window, no drift tolerance) — simplified from a
        // hand-rolled HMAC-SHA1 reimplementation that had two type
        // errors (decbin()/chr() fed possibly-false/float values from
        // an unvalidated base32 decode) and duplicated logic
        // Google2FA already exposes.
        return $this->google2fa->getCurrentOtp($secret);
    }
}
