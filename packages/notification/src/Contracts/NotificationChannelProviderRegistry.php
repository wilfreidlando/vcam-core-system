<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\Credentials;
use Core\Support\Integration\Provider\ProviderCode;

/**
 * Module-scoped, typed wrapper around the generic `ProviderRegistry`
 * (`core/support/Integration/Provider`) — mirrors
 * `Core\Payment\Contracts\PaymentGatewayRegistry` (ADR-0041 §2).
 */
interface NotificationChannelProviderRegistry
{
    /**
     * @throws UnsupportedNotificationChannelProviderException when the resolved
     *                                                         gateway is not a `NotificationChannelProvider`.
     */
    public function gatewayFor(ProviderCode $code): NotificationChannelProvider;

    public function configFor(ProviderCode $code): ProviderConfig;

    public function defaultCredentialsFor(ProviderCode $code): Credentials;

    /**
     * @return list<ProviderCode>
     */
    public function availableProviders(): array;
}
