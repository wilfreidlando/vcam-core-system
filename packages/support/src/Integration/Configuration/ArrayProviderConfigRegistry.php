<?php

declare(strict_types=1);

namespace Core\Support\Integration\Configuration;

use Core\Support\Integration\Credentials;
use Core\Support\Integration\Provider\ProviderCode;

/**
 * Default `ProviderConfigRegistry` impl — reads the array shape produced
 * by `config/providers.php` and lazy-builds `ProviderConfig` instances
 * on first access.
 *
 * The raw array shape expected:
 *
 *   [
 *     'payment' => [
 *       'my_coolpay' => [
 *         'code' => 'my-coolpay',
 *         'base_url' => '...',
 *         'auth' => [...],
 *         'credentials' => [...],
 *         'timeouts' => [...],
 *         'retries' => [...],
 *         'breaker' => [...],
 *         'extras' => [...],
 *       ],
 *     ],
 *     'notification' => [...],
 *   ]
 *
 * The top-level grouping by domain (`payment`, `notification`, ...) is
 * preserved for readability of the config file but ignored by this
 * loader — the provider code at the deepest level is the identifier.
 *
 * The type doc is `array<string, mixed>` (not the stricter nested
 * shape) because `config/providers.php` carries an informational
 * `environment` key at the top level that is NOT a provider. The
 * loader tolerates that and any other non-provider keys.
 */
final class ArrayProviderConfigRegistry implements ProviderConfigRegistry
{
    /**
     * @param array<string, mixed> $rawProviders
     *                                           Outer key is a domain label (informational only),
     *                                           inner key is the provider code, value is the config array.
     */
    public function __construct(private readonly array $rawProviders) {}

    public function configFor(ProviderCode $code): ProviderConfig
    {
        $data = $this->rawDataFor($code);

        /** @var array<string, mixed> $data */
        return ProviderConfig::fromArray($data);
    }

    public function defaultCredentialsFor(ProviderCode $code): Credentials
    {
        return $this->configFor($code)->defaultCredentials;
    }

    public function has(ProviderCode $code): bool
    {
        foreach ($this->rawProviders as $domain) {
            if (! is_array($domain)) {
                continue;
            }
            foreach ($domain as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                if (($entry['code'] ?? null) === $code->toString()) {
                    return true;
                }
            }
        }

        return false;
    }

    public function availableProviders(): array
    {
        $codes = [];
        foreach ($this->rawProviders as $domain) {
            if (! is_array($domain)) {
                continue;
            }
            foreach ($domain as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $raw = $entry['code'] ?? null;
                if (! is_string($raw) || $raw === '') {
                    continue;
                }
                $codes[] = ProviderCode::fromString($raw);
            }
        }

        /** @var list<ProviderCode> $codes */
        return array_values(array_unique($codes, SORT_STRING));
    }

    /**
     * @return array<string, mixed>
     */
    private function rawDataFor(ProviderCode $code): array
    {
        $data = $this->rawDataForOrNull($code);
        if ($data === null) {
            throw new ProviderNotConfigured($code);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rawDataForOrNull(ProviderCode $code): ?array
    {
        foreach ($this->rawProviders as $domain) {
            if (! is_array($domain)) {
                continue;
            }
            foreach ($domain as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                if (($entry['code'] ?? null) === $code->toString()) {
                    /** @var array<string, mixed> $entry */
                    return $entry;
                }
            }
        }

        return null;
    }
}
