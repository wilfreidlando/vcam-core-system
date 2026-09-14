<?php

declare(strict_types=1);

namespace Core\Support\Integration\Auth;

/**
 * Port for the OAuth access-token cache used by `BearerAuthProvider`.
 *
 * The foundation does NOT provide an implementation: a default in-memory
 * cache would silently mask a misconfiguration in production (each PHP
 * worker would refresh its own token, multiplying load and audit noise
 * against the provider's token endpoint).
 *
 * The application shell binds a real implementation — typically
 * Valkey-backed — during boot.
 *
 * The cache is keyed by a **fully-qualified string** built by the caller
 * (typically `"{providerCode}:{credentialsFingerprint}"`). This lets
 * the bearer provider scope a token to a specific set of credentials,
 * which is required for multi-tenant correctness: two tenants with
 * different `client_id` / `client_secret` pairs MUST NOT share a token.
 */
interface TokenCache
{
    public function get(string $key): ?Token;

    public function put(string $key, Token $token): void;

    public function forget(string $key): void;
}
