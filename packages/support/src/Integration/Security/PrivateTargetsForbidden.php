<?php

declare(strict_types=1);

namespace Core\Support\Integration\Security;

use RuntimeException;

/**
 * ADR-0115 — `OUTBOUND_ALLOW_PRIVATE_TARGETS=true` lets the Core call an
 * application that listens on a private address (RFC 1918, unique-local
 * IPv6): the local Docker network a developer runs the Core and the client
 * application on.
 *
 * It is refused at startup outside `APP_ENV=local`, **in code**, whatever
 * the configuration says: no environment variable alone can open the
 * private ranges on a deployment that faces the internet. The loopback and
 * the link-local range (which holds the cloud metadata address) stay
 * refused even in local — `localhost` means the Core itself, never the
 * client application.
 */
final class PrivateTargetsForbidden extends RuntimeException
{
    public static function inEnvironment(string $environment): self
    {
        return new self(sprintf(
            'OUTBOUND_ALLOW_PRIVATE_TARGETS=true est interdit hors de l\'environnement « local » (environnement courant : « %s ») : un point d\'entrée doit être une adresse publique. Retirez ce drapeau.',
            $environment,
        ));
    }
}
