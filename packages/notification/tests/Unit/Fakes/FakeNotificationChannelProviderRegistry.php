<?php

declare(strict_types=1);

namespace Core\Notification\Tests\Unit\Fakes;

use Core\Notification\Contracts\NotificationChannelProvider;
use Core\Notification\Contracts\NotificationChannelProviderRegistry;
use Core\Notification\Contracts\UnsupportedNotificationChannelProviderException;
use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\Credentials;
use Core\Support\Integration\Provider\ProviderCode;
use LogicException;

final class FakeNotificationChannelProviderRegistry implements NotificationChannelProviderRegistry
{
    /** @var array<string, NotificationChannelProvider> */
    private array $gateways = [];

    public function register(NotificationChannelProvider $gateway): void
    {
        $this->gateways[$gateway->providerCode()->toString()] = $gateway;
    }

    public function gatewayFor(ProviderCode $code): NotificationChannelProvider
    {
        return $this->gateways[$code->toString()] ?? throw new UnsupportedNotificationChannelProviderException($code, 'null');
    }

    public function configFor(ProviderCode $code): ProviderConfig
    {
        throw new LogicException('Not used by this fake.');
    }

    public function defaultCredentialsFor(ProviderCode $code): Credentials
    {
        throw new LogicException('Not used by this fake.');
    }

    public function availableProviders(): array
    {
        return array_map(
            static fn (NotificationChannelProvider $g): ProviderCode => $g->providerCode(),
            array_values($this->gateways),
        );
    }
}
