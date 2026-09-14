<?php

declare(strict_types=1);

namespace Core\Support\Integration\Configuration;

use Core\Support\Integration\Credentials;
use Core\Support\Integration\Provider\ProviderCode;
use InvalidArgumentException;

/**
 * Complete configuration for one outbound integration with one provider.
 *
 * Built once at boot from `config/providers.php` (via `ProviderConfig::fromArray`)
 * and held in memory by the registry. Adapters receive this VO through
 * constructor injection and never read `config()` themselves — that keeps
 * tests deterministic and prevents accidental reads at request time.
 *
 * Carries structural configuration (URL, timeouts, auth shape, signature
 * template) AND default credentials. The default credentials are the
 * "fallback" used when a caller does not supply its own; the caller can
 * always override them through `ExternalRequest::credentials`.
 *
 * Everything inside is `readonly`; a config that needs to change (because
 * the app shell reloads the registry, for instance) is replaced wholesale.
 */
final readonly class ProviderConfig
{
    /**
     * @param array<string, mixed> $extras
     */
    public function __construct(
        public ProviderCode $code,
        public string $baseUrl,
        public AuthStrategy $auth,
        public TimeoutPolicy $timeouts,
        public RetryPolicy $retries,
        public ?SignaturePolicy $signature,
        public CircuitBreakerPolicy $breaker,
        public Credentials $defaultCredentials,
        /** Kill switch — false means refuse the call with ProviderDisabledException. */
        public bool $enabled = true,
        /**
         * Verbose logging flag — when true, the caller logs the redacted
         * request and response bodies on every attempt (default off,
         * mirrors the Symfony `debugRequest`/`debugResponse` flags).
         * Useful when integrating a new provider; never enable in
         * production — the bodies can carry PII even after redaction.
         */
        public bool $debug = false,
        /** Provider-specific free-form data (subscription keys, merchant IDs…). */
        public array $extras = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['code', 'base_url', 'auth', 'timeouts', 'retries', 'breaker'] as $required) {
            if (! array_key_exists($required, $data)) {
                throw new InvalidArgumentException(sprintf(
                    'ProviderConfig.%s is required.',
                    $required,
                ));
            }
        }
        if (! is_string($data['code']) || $data['code'] === '') {
            throw new InvalidArgumentException('ProviderConfig.code must be a non-empty string.');
        }
        if (! is_string($data['base_url']) || $data['base_url'] === '') {
            throw new InvalidArgumentException('ProviderConfig.base_url must be a non-empty string.');
        }

        $authData = self::subArray($data, 'auth');
        $timeoutsData = self::subArray($data, 'timeouts');
        $retriesData = self::subArray($data, 'retries');
        $breakerData = self::subArray($data, 'breaker');

        $signatureData = $data['signature'] ?? null;
        $credentialsData = $data['credentials'] ?? null;
        $extrasData = $data['extras'] ?? [];

        return new self(
            code: ProviderCode::fromString($data['code']),
            baseUrl: rtrim($data['base_url'], '/'),
            auth: AuthStrategy::fromArray($authData),
            timeouts: TimeoutPolicy::fromArray($timeoutsData),
            retries: RetryPolicy::fromArray($retriesData),
            signature: is_array($signatureData) ? SignaturePolicy::fromArray(self::typedArray($signatureData, 'signature')) : null,
            breaker: CircuitBreakerPolicy::fromArray($breakerData),
            defaultCredentials: is_array($credentialsData)
                ? Credentials::fromArray(self::typedArray($credentialsData, 'credentials'))
                : Credentials::empty(),
            enabled: (bool) ($data['enabled'] ?? true),
            debug: (bool) ($data['debug'] ?? false),
            extras: is_array($extrasData) ? self::typedArray($extrasData, 'extras') : [],
        );
    }

    /**
     * @template T
     *
     * @param T $default
     * @return mixed|T
     */
    public function extra(string $key, mixed $default = null): mixed
    {
        return $this->extras[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    private static function typedArray(mixed $value, string $key): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException(sprintf(
                'ProviderConfig.%s must be an array<string, mixed>.',
                $key,
            ));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function subArray(array $data, string $key): array
    {
        if (! is_array($data[$key])) {
            throw new InvalidArgumentException(sprintf(
                'ProviderConfig.%s must be an array.',
                $key,
            ));
        }
        /** @var array<string, mixed> $sub */
        $sub = $data[$key];

        return $sub;
    }
}
