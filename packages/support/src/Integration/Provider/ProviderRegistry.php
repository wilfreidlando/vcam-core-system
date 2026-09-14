<?php

declare(strict_types=1);

namespace Core\Support\Integration\Provider;

use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\Credentials;

/**
 * Port for the central registry of provider integrations.
 *
 * Application use cases call `gatewayFor()` rather than `match`-ing on a
 * provider code (ADR-0026). The registry owns the wiring between
 * provider codes and their concrete adapters; the rest of the codebase
 * only knows the port.
 *
 * The registry also exposes the default credentials per provider
 * (`defaultCredentialsFor()`). Callers that need to act on behalf of a
 * specific tenant/merchant/project read those credentials, merge them
 * with any per-call override, and pass the result through
 * `ExternalRequest::credentials`.
 *
 * Implementations live in the modules that have providers (e.g.
 * `Payment\Infrastructure\ProviderRegistryImpl`). They are built at boot
 * by binding tagged `ProviderGateway` instances.
 */
interface ProviderRegistry
{
    /**
     * @throws UnsupportedProviderException when no adapter is registered for the code.
     */
    public function gatewayFor(ProviderCode $code): ProviderGateway;

    public function configFor(ProviderCode $code): ProviderConfig;

    /**
     * Default credentials loaded from `config/providers.php`. The
     * `ExternalApiCaller` merges these with any per-request credentials
     * supplied via `ExternalRequest::credentials`.
     *
     * @throws UnsupportedProviderException when no config is registered for the code.
     */
    public function defaultCredentialsFor(ProviderCode $code): Credentials;

    /**
     * @return list<ProviderCode>
     */
    public function availableProviders(): array;
}
