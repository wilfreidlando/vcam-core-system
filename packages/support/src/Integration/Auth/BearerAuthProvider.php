<?php

declare(strict_types=1);

namespace Core\Support\Integration\Auth;

use Core\Support\Integration\Configuration\AuthKind;
use Core\Support\Integration\Configuration\AuthStrategy;
use Core\Support\Integration\Credentials;
use Core\Support\Integration\Provider\ProviderCode;
use InvalidArgumentException;

/**
 * Static Bearer-token authentication.
 *
 * The credential IS the token. No fetching, no refresh, no caching —
 * Firebase server keys, vendor-supplied fixed tokens, and similar
 * setups. When the provider requires an OAuth2 token-endpoint flow
 * (e.g. MTN MoMo), the adapter composes an OAuth2 helper (using the
 * foundation's transport + token cache + basic auth primitives) to
 * fetch the token first, then uses this provider to apply it.
 *
 * No I/O, no caching — the token is read from the supplied credentials
 * on every call.
 */
final class BearerAuthProvider implements AuthProvider
{
    public function supports(AuthKind $kind): bool
    {
        return $kind === AuthKind::Bearer;
    }

    public function apply(
        ProviderCode $code,
        AuthStrategy $strategy,
        Credentials $credentials,
        array $existingHeaders,
        mixed $currentBody,
    ): AuthApplyResult {
        if ($credentials->bearerToken === null || $credentials->bearerToken === '') {
            throw new InvalidArgumentException(sprintf(
                'Bearer auth for "%s" requires a non-empty bearer_token credential.',
                $code->toString(),
            ));
        }

        return new AuthApplyResult(
            headers: $existingHeaders + ['Authorization' => 'Bearer '.$credentials->bearerToken],
            body: null,
        );
    }
}
