<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Provider;

use Core\Payment\Contracts\PaymentGateway;
use Core\Payment\Contracts\PaymentGatewayRegistry;
use Core\Payment\Contracts\UnsupportedPaymentGatewayException;
use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\Credentials;
use Core\Support\Integration\Provider\ProviderCode;
use Core\Support\Integration\Provider\ProviderRegistry;

/**
 * Module-scoped wrapper around the generic `ProviderRegistry`. Use cases
 * in the payment module depend on this — never on the generic — so
 * the type system narrows automatically to `PaymentGateway`.
 *
 * Construction:
 *   1. Bind a generic `ProviderRegistry` in the application shell
 *      (the foundation's `IntegrationServiceProvider` does this — see
 *      `TaggedProviderRegistry`).
 *   2. Bind this wrapper as `PaymentGatewayRegistry`, passing the
 *      generic registry in the constructor.
 *
 * The cast in `gatewayFor()` is the only piece of behaviour — it
 * fails loudly when the generic registry resolves a code to a gateway
 * that is NOT a `PaymentGateway` (the most common case would be a
 * future where Notification registers a `sms-orange` gateway with the
 * same code as `payment-orange`; but with `DuplicateProviderCodeException`
 * throwing at boot, that case is already prevented upstream).
 */
final class PaymentGatewayRegistryImpl implements PaymentGatewayRegistry
{
    public function __construct(private readonly ProviderRegistry $generic) {}

    public function gatewayFor(ProviderCode $code): PaymentGateway
    {
        $gateway = $this->generic->gatewayFor($code);
        if (! $gateway instanceof PaymentGateway) {
            throw new UnsupportedPaymentGatewayException($code, $gateway::class);
        }

        /** @var PaymentGateway $gateway */
        return $gateway;
    }

    public function configFor(ProviderCode $code): ProviderConfig
    {
        return $this->generic->configFor($code);
    }

    public function defaultCredentialsFor(ProviderCode $code): Credentials
    {
        return $this->generic->defaultCredentialsFor($code);
    }

    /**
     * The generic registry's own `availableProviders()` returns every
     * configured provider platform-wide — payment AND notification alike
     * (both share the same `provider.gateway` tag and the same
     * `ProviderConfigRegistry`, per `TaggedProviderRegistry`) — so a bare
     * delegation here used to leak notification-only providers (e.g.
     * `waha`) into a payment-scoped listing (review finding: the admin
     * console's provider-application dropdown offered a WhatsApp adapter
     * as if it were a payment option). Filtering through `gatewayFor()`'s
     * own `PaymentGateway` check is the same narrowing `gatewayFor()`
     * already does for a single code, just applied to the whole list.
     *
     * @return list<ProviderCode>
     */
    public function availableProviders(): array
    {
        return array_values(array_filter(
            $this->generic->availableProviders(),
            fn (ProviderCode $code): bool => $this->generic->gatewayFor($code) instanceof PaymentGateway,
        ));
    }
}
