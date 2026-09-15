<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Provider;

use Core\Notification\Contracts\NotificationChannelProvider;
use Core\Notification\Contracts\NotificationChannelProviderRegistry;
use Core\Notification\Contracts\UnsupportedNotificationChannelProviderException;
use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\Credentials;
use Core\Support\Integration\Provider\ProviderCode;
use Core\Support\Integration\Provider\ProviderRegistry;

/**
 * Module-scoped wrapper around the generic `ProviderRegistry` — mirrors
 * `Core\Payment\Infrastructure\Provider\PaymentGatewayRegistryImpl`
 * (ADR-0041 §2). No `NotificationChannelProvider` is tagged yet (no
 * concrete adapter exists), so `gatewayFor()` will throw
 * `UnsupportedNotificationChannelProviderException` for every code until
 * one is.
 */
final class NotificationChannelProviderRegistryImpl implements NotificationChannelProviderRegistry
{
    public function __construct(private readonly ProviderRegistry $generic) {}

    public function gatewayFor(ProviderCode $code): NotificationChannelProvider
    {
        $gateway = $this->generic->gatewayFor($code);
        if (! $gateway instanceof NotificationChannelProvider) {
            throw new UnsupportedNotificationChannelProviderException($code, $gateway::class);
        }

        /** @var NotificationChannelProvider $gateway */
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

    public function availableProviders(): array
    {
        return $this->generic->availableProviders();
    }
}
