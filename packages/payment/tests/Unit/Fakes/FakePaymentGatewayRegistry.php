<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Payment\Contracts\PaymentGateway;
use Core\Payment\Contracts\PaymentGatewayRegistry;
use Core\Payment\Contracts\UnsupportedPaymentGatewayException;
use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\Credentials;
use Core\Support\Integration\Provider\ProviderCode;
use LogicException;

final class FakePaymentGatewayRegistry implements PaymentGatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    public function register(PaymentGateway $gateway): void
    {
        $this->gateways[$gateway->providerCode()->toString()] = $gateway;
    }

    public function gatewayFor(ProviderCode $code): PaymentGateway
    {
        return $this->gateways[$code->toString()] ?? throw new UnsupportedPaymentGatewayException($code, 'null');
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
        return array_map(static fn (PaymentGateway $g): ProviderCode => $g->providerCode(), array_values($this->gateways));
    }
}
