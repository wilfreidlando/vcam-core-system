<?php

declare(strict_types=1);

namespace Core\Support\Integration;

use InvalidArgumentException;

/**
 * Credentials presented to one provider on one call.
 *
 * Two complementary patterns coexist:
 *
 *  - **Static credentials** are loaded once at boot from
 *    `config/providers.php` and used as defaults for every call to the
 *    provider. The simplest case — one provider, one set of keys.
 *
 *  - **Per-call credentials** are built by the caller (e.g. a use case
 *    reading the merchant's record) and supplied through
 *    `ExternalRequest::credentials`. This is the multi-tenant case —
 *    the same provider is integrated once but is called with a
 *    different merchant's credentials each time.
 *
 * Per-call credentials override static ones field-by-field: an override
 * that only sets `apiKey` keeps the static `clientId`. The merge logic
 * is in {@see self::mergeWith()}.
 *
 * Fields are intentionally **flat** rather than grouped by auth kind:
 * each auth provider only reads the field(s) it needs and ignores the
 * rest. This avoids having to construct different `Credentials` shapes
 * depending on the strategy, and lets a caller pass *all* the
 * credentials it has available without filtering per provider.
 *
 * SECURITY: every field on this VO is treated as secret. The
 * `PayloadRedactor` recognises every field name. The `fingerprint()`
 * method exposes a stable hash suitable for cache keys and metric
 * labels, without leaking the underlying values.
 */
final readonly class Credentials
{
    public function __construct(
        // ApiKey
        public ?string $apiKey = null,
        // Basic
        public ?string $username = null,
        public ?string $password = null,
        // Bearer (static token — the credential IS the token)
        public ?string $bearerToken = null,
        // OAuth2 client_credentials (token-endpoint flow, used by
        // OAuth2ClientCredentialsAuthProvider, not by the bearer path)
        public ?string $clientId = null,
        public ?string $clientSecret = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            apiKey: self::stringOrNull($data, 'api_key'),
            username: self::stringOrNull($data, 'username'),
            password: self::stringOrNull($data, 'password'),
            bearerToken: self::stringOrNull($data, 'bearer_token'),
            clientId: self::stringOrNull($data, 'client_id'),
            clientSecret: self::stringOrNull($data, 'client_secret'),
        );
    }

    public static function empty(): self
    {
        return new self;
    }

    /**
     * Returns a new instance where fields present in $override win over
     * the current instance. A null in the override is ignored — it does
     * NOT wipe the static default value.
     */
    public function mergeWith(self $override): self
    {
        return new self(
            apiKey: $override->apiKey ?? $this->apiKey,
            username: $override->username ?? $this->username,
            password: $override->password ?? $this->password,
            bearerToken: $override->bearerToken ?? $this->bearerToken,
            clientId: $override->clientId ?? $this->clientId,
            clientSecret: $override->clientSecret ?? $this->clientSecret,
        );
    }

    /**
     * Stable one-way hash of the credential values. Used as part of
     * cache keys (e.g. the OAuth2 token cache) when several callers
     * share the same provider but have distinct credentials.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'api_key' => $this->apiKey,
            'username' => $this->username,
            'password' => $this->password,
            'bearer_token' => $this->bearerToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function stringOrNull(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (! is_string($data[$key])) {
            throw new InvalidArgumentException(sprintf(
                'Credentials.%s must be a string when present.',
                $key,
            ));
        }

        return $data[$key];
    }
}
