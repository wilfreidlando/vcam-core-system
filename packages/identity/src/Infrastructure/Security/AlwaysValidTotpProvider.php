<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Security;

use Core\Identity\Domain\Port\TotpProvider;

/**
 * Accepts any code, for any secret — bound in place of
 * `Google2faTotpProvider` only when `APP_ENV=local` **and**
 * `IDENTITY_DISABLE_TOTP_IN_LOCAL=true` (both checked in
 * `IdentityServiceProvider::register()`, not just the config flag: the
 * environment check happens in code, so no config value can enable this
 * outside `local`). Removes the friction of re-scanning a TOTP secret
 * into an authenticator app on every local rebuild — never reachable
 * anywhere a real operator's credentials matter.
 */
final class AlwaysValidTotpProvider implements TotpProvider
{
    private const string DEV_SECRET = 'LOCALDEVTOTPDISABLED';

    public function generateSecret(): string
    {
        return self::DEV_SECRET;
    }

    /**
     * Any code verifies. The returned "timestep" only needs to keep
     * increasing (`Operator::recordMfaVerification()` just stores it —
     * real anti-replay enforcement is meaningless once verification
     * itself is disabled), so a microsecond counter stands in for the
     * real 30s RFC 6238 timestep.
     */
    public function verify(string $secret, string $code, ?int $afterTimestep = null): int
    {
        return (int) (microtime(true) * 1_000_000);
    }

    public function buildOtpauthUri(string $secret, string $email): string
    {
        return 'otpauth://totp/DEV-MODE-TOTP-DISABLED:'.rawurlencode($email).'?secret='.$secret.'&issuer=DEV-MODE-TOTP-DISABLED';
    }

    public function currentCode(string $secret): string
    {
        return '000000';
    }
}
