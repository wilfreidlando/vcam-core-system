<?php

declare(strict_types=1);

namespace Core\Support\Integration\Auth;

use Core\Support\Integration\Exception\TokenCacheUnavailableException;

/**
 * Sentinel `TokenCache` used when no real implementation is bound.
 *
 * The bearer auth provider's constructor accepts any `TokenCache`
 * implementation so that tests can inject a fake without dealing with
 * unavailable cache exceptions. In production, the application shell
 * must rebind `TokenCache` to a real (Valkey-backed) implementation.
 *
 * This class makes the missing binding loud: every `put()` throws
 * `TokenCacheUnavailableException` so the operator notices the
 * misconfiguration immediately rather than discovering it as "every
 * request re-fetches the token" in production.
 *
 * In practice, the foundation's `IntegrationServiceProvider` binds
 * `TokenCache` to a `NullTokenCache` instance so the application boots
 * cleanly. A boot-time assertion (or a domain rule) should be added in
 * the application shell to ensure this is rebound in production
 * environments where Bearer auth is actually used.
 */
final class NullTokenCache implements TokenCache
{
    public function get(string $key): ?Token
    {
        return null;
    }

    public function put(string $key, Token $token): void
    {
        throw new TokenCacheUnavailableException(
            'Token cache is unavailable (NullTokenCache). Bind a real implementation (Valkey) in the application shell.',
            \Core\Support\Integration\Provider\ProviderCode::fromString('unset'),
        );
    }

    public function forget(string $key): void
    {
        // No-op: nothing to forget.
    }
}
