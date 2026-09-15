<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\Credentials;
use Core\Support\Integration\Provider\ProviderCode;

/**
 * Module-scoped, typed gateway registry.
 *
 * The generic `ProviderRegistry` (in `core/support/Integration/Provider`)
 * returns `ProviderGateway` — the common base interface shared by every
 * adapter. Use cases inside the payment module call
 * `->initiate(...)` / `->submitAction(...)` / `->checkStatus(...)`, which
 * are only on the payment-specific `PaymentGateway` interface. PHP has
 * no runtime generics, so the generic registry cannot narrow the type.
 *
 * This wrapper sits in front of the generic registry, performs the
 * cast, and throws a clear error if the resolved gateway is not in fact
 * a `PaymentGateway`. A use case in the payment module then types
 * against `PaymentGatewayRegistry` and never has to do
 * `instanceof` / cast itself.
 *
 * The wrapper is a thin, one-method-per-delegation adapter — the generic
 * registry stays the single source of truth. Each module that has
 * adapters (payment, notification, …) defines its own typed wrapper.
 */
interface PaymentGatewayRegistry
{
    /**
     * @throws UnsupportedPaymentGatewayException when the resolved
     *                                            gateway is not an instance of `PaymentGateway`.
     */
    public function gatewayFor(ProviderCode $code): PaymentGateway;

    public function configFor(ProviderCode $code): ProviderConfig;

    public function defaultCredentialsFor(ProviderCode $code): Credentials;

    /**
     * @return list<ProviderCode>
     */
    public function availableProviders(): array;
}
