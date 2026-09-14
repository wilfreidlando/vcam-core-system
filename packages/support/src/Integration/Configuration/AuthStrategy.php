<?php

declare(strict_types=1);

namespace Core\Support\Integration\Configuration;

use InvalidArgumentException;

/**
 * Structural shape of one provider's authentication.
 *
 * Carries everything that is **stable for the provider**, not for the
 * caller: the kind of auth, where to fetch a token, which header to
 * carry an API key in, etc. The actual secret values (client id,
 * secret, password, API key, bearer token) live in
 * {@see \Core\Support\Integration\Credentials} so they can be supplied
 * per-request by the caller (multi-tenant, per-merchant, per-project).
 *
 * Loading this VO from configuration is structural: the auth shape does
 * not change between callers, so reading it once at boot from
 * `config/providers.php` is correct.
 *
 * Note on OAuth2: providers that expose a token endpoint (MTN MoMo,
 * certain banking APIs) need an extra hop to obtain a bearer token
 * before calling the real endpoint. That flow is **not** modelled in
 * this VO — it is a saga owned by the adapter, which composes a
 * `BasicAuthProvider` call (to the token endpoint) followed by a
 * `BearerAuthProvider` call (with the fetched token). Keeping the
 * token-endpoint dance out of the auth strategy keeps the common case
 * (API key in header, static bearer, basic) simple.
 */
final readonly class AuthStrategy
{
    public function __construct(
        public AuthKind $kind,
        // ApiKey — structural
        public ?string $apiKeyHeader = null,
        public ApiKeyPlacement $apiKeyPlacement = ApiKeyPlacement::Header,
        public ?string $apiKeyBodyField = null,
        // Bearer — no structural field; the credential IS the token
        // Basic — no structural field; the credentials ARE user/password
        // mTLS — left as future work
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (! isset($data['kind']) || ! is_string($data['kind'])) {
            throw new InvalidArgumentException('AuthStrategy.kind is required and must be a string.');
        }
        $kind = AuthKind::tryFrom($data['kind']);
        if ($kind === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown AuthStrategy.kind "%s". Allowed: %s.',
                $data['kind'],
                implode(', ', array_map(static fn (AuthKind $k) => $k->value, AuthKind::cases())),
            ));
        }

        $placement = ApiKeyPlacement::Header;
        if (isset($data['api_key_placement']) && is_string($data['api_key_placement'])) {
            $placementCandidate = ApiKeyPlacement::tryFrom($data['api_key_placement']);
            if ($placementCandidate === null) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown AuthStrategy.api_key_placement "%s". Allowed: %s.',
                    $data['api_key_placement'],
                    implode(', ', array_map(static fn (ApiKeyPlacement $p) => $p->value, ApiKeyPlacement::cases())),
                ));
            }
            $placement = $placementCandidate;
        }

        return new self(
            kind: $kind,
            apiKeyHeader: self::stringOrNull($data, 'api_key_header'),
            apiKeyPlacement: $placement,
            apiKeyBodyField: self::stringOrNull($data, 'api_key_body_field'),
        );
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
                'AuthStrategy.%s must be a string when present.',
                $key,
            ));
        }

        return $data[$key];
    }
}
