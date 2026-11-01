<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * Audit 2026-10-29 #12 — `IDENTITY_DISABLE_MFA=true` removes the second
 * factor of the whole console (web and API). It is refused at startup
 * outside `APP_ENV=local`, in code, whatever the configuration says.
 */
final class MfaBypassForbidden extends RuntimeException
{
    public static function inEnvironment(string $environment): self
    {
        return new self(sprintf('IDENTITY_DISABLE_MFA=true est interdit hors de l\'environnement « local » (environnement courant : « %s ») : retirez ce drapeau.', $environment));
    }
}
