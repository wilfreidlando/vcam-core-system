<?php

declare(strict_types=1);

namespace Core\Support\Integration\Provider;

use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\Configuration\ProviderConfigRegistry;
use Core\Support\Integration\Credentials;

/**
 * Default `ProviderRegistry` implementation — indexes tagged
 * `ProviderGateway` instances by their `providerCode()` and dispatches
 * `gatewayFor()` lookups.
 *
 * Construction contract:
 *  - the iterable of tagged gateways is provided by Laravel's
 *    `tagged('provider.gateway')` collector
 *  - the `ProviderConfigRegistry` is bound separately (it owns the
 *    configuration reading, this class only owns the gateway indexing)
 *
 * When a new provider is added, no code change is needed here — the
 * tag in the module's service provider is the only addition.
 */
final class TaggedProviderRegistry implements ProviderRegistry
{
    /** @var array<string, ProviderGateway> */
    private readonly array $indexed;

    /**
     * @param iterable<ProviderGateway> $gateways All ProviderGateway implementations,
     *                                            tagged in the service container
     *                                            (typically 'provider.gateway').
     *
     * @throws DuplicateProviderCodeException when two gateways claim the same
     *                                        code. We fail loudly at boot rather than silently overwriting —
     *                                        a duplicate usually means two modules both tagged an adapter
     *                                        for the same code (e.g. payment.Orange and notification.Orange)
     *                                        and silently overwriting one would route requests to whichever
     *                                        module registered last.
     */
    public function __construct(
        iterable $gateways,
        private readonly ProviderConfigRegistry $configs,
    ) {
        $byCode = [];
        foreach ($gateways as $gateway) {
            $code = $gateway->providerCode();
            $key = $code->toString();
            if (isset($byCode[$key])) {
                throw new DuplicateProviderCodeException(
                    $code,
                    $byCode[$key],
                    $gateway,
                );
            }
            $byCode[$key] = $gateway;
        }
        $this->indexed = $byCode;
    }

    public function gatewayFor(ProviderCode $code): ProviderGateway
    {
        if (! isset($this->indexed[$code->toString()])) {
            throw new UnsupportedProviderException($code);
        }

        return $this->indexed[$code->toString()];
    }

    public function configFor(ProviderCode $code): ProviderConfig
    {
        return $this->configs->configFor($code);
    }

    public function defaultCredentialsFor(ProviderCode $code): Credentials
    {
        return $this->configs->defaultCredentialsFor($code);
    }

    public function availableProviders(): array
    {
        return $this->configs->availableProviders();
    }
}
