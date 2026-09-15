<?php

declare(strict_types=1);

namespace Core\Support\Integration\Configuration;

use Core\Support\Integration\Credentials;
use Core\Support\Integration\Provider\ProviderCode;

/**
 * Port for the lookup of provider configuration loaded from
 * `config/providers.php` at boot.
 *
 * Separated from `ProviderRegistry` so that:
 *  - Pure configuration queries (e.g. from a console command listing
 *    enabled providers) don't need to resolve a gateway instance.
 *  - Adapters constructed outside the registry (e.g. by Lot 3
 *    business modules that need the config for something other than
 *    HTTP) can ask the config registry directly.
 *
 * The typical implementation `ArrayProviderConfigRegistry` reads the
 * raw array shape of `config/providers.php` and lazy-builds
 * `ProviderConfig` instances on first access. Pure technical primitive
 * — no business logic.
 */
interface ProviderConfigRegistry
{
    /**
     * @throws ProviderNotConfigured when no config exists for the code.
     */
    public function configFor(ProviderCode $code): ProviderConfig;

    /**
     * Convenience accessor for the default credentials of a provider.
     * Equivalent to `configFor($code)->defaultCredentials`.
     *
     * @throws ProviderNotConfigured when no config exists for the code.
     */
    public function defaultCredentialsFor(ProviderCode $code): Credentials;

    public function has(ProviderCode $code): bool;

    /**
     * @return list<ProviderCode>
     */
    public function availableProviders(): array;
}
