<?php

declare(strict_types=1);

namespace Core\Support\Integration\Auth;

use Core\Support\Integration\Configuration\AuthKind;
use Core\Support\Integration\Configuration\AuthStrategy;
use Core\Support\Integration\Credentials;
use Core\Support\Integration\Provider\ProviderCode;
use InvalidArgumentException;

/**
 * HTTP Basic authentication.
 *
 * Produces `Authorization: Basic base64(user:password)` from the supplied
 * credentials' `username` and `password` fields. No caching, no refresh,
 * no I/O — the credentials are static per call and re-encoded on every
 * call (encoding is constant-time-cheap and avoids any concern about
 * in-memory string reuse).
 *
 * A missing username or password is reported as an
 * `InvalidArgumentException` built from the provider code only — the
 * credential values themselves are never echoed in error messages.
 */
final class BasicAuthProvider implements AuthProvider
{
    public function supports(AuthKind $kind): bool
    {
        return $kind === AuthKind::Basic;
    }

    public function apply(
        ProviderCode $code,
        AuthStrategy $strategy,
        Credentials $credentials,
        array $existingHeaders,
        mixed $currentBody,
    ): AuthApplyResult {
        if ($credentials->username === null || $credentials->password === null) {
            throw new InvalidArgumentException(sprintf(
                'Basic auth for "%s" requires username and password credentials.',
                $code->toString(),
            ));
        }

        $token = base64_encode($credentials->username.':'.$credentials->password);

        return new AuthApplyResult(
            headers: $existingHeaders + ['Authorization' => 'Basic '.$token],
            body: null,
        );
    }
}
