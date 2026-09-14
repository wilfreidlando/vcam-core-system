<?php

declare(strict_types=1);

namespace Core\Support\Integration\Provider;

use Core\Support\Integration\Configuration\ProviderConfig;

/**
 * Port for a registered outbound provider integration.
 *
 * Minimal by design: the foundation only needs to identify a provider
 * and retrieve its configuration. Concrete operations (`initiate`,
 * `refund`, …) are added by the modules that own the provider at
 * Lot 3 (e.g. `Payment\Application\Gateway\PaymentGateway extends
 * ProviderGateway`).
 *
 * Keeping the port minimal lets the foundation compile and test before
 * any concrete adapter exists (ADR-0026: the foundation is built now,
 * adapters are extracted from real providers at Lot 3).
 */
interface ProviderGateway
{
    public function providerCode(): ProviderCode;

    public function config(): ProviderConfig;
}
