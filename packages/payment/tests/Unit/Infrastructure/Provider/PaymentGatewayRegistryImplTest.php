<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Infrastructure\Provider;

use Core\Payment\Contracts\InitiatePaymentCommand;
use Core\Payment\Contracts\PaymentAction;
use Core\Payment\Contracts\PaymentGateway;
use Core\Payment\Contracts\PaymentGatewayRegistry;
use Core\Payment\Contracts\PaymentReceipt;
use Core\Payment\Contracts\UnsupportedPaymentGatewayException;
use Core\Payment\Infrastructure\Provider\PaymentGatewayRegistryImpl;
use Core\Payment\PaymentStatus;
use Core\Support\Integration\Configuration\ArrayProviderConfigRegistry;
use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\Configuration\ProviderConfigRegistry;
use Core\Support\Integration\Credentials;
use Core\Support\Integration\Provider\ProviderCode;
use Core\Support\Integration\Provider\ProviderGateway;
use Core\Support\Integration\Provider\ProviderRegistry;
use Core\Support\Integration\Provider\UnsupportedProviderException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the typed `PaymentGatewayRegistry`. Covers:
 *  - delegation to the generic registry (gatewayFor, configFor, etc.)
 *  - type narrowing (returns a PaymentGateway, not just a ProviderGateway)
 *  - the cast error when the generic registry resolves to a
 *    non-PaymentGateway
 */
final class PaymentGatewayRegistryImplTest extends TestCase
{
    #[Test]
    public function gateway_for_returns_a_payment_gateway_when_one_is_indexed(): void
    {
        $payment = $this->makePaymentGateway('my-coolpay');
        $generic = $this->makeGenericRegistry([$payment]);
        $typed = new PaymentGatewayRegistryImpl($generic);

        $resolved = $typed->gatewayFor(ProviderCode::fromString('my-coolpay'));

        self::assertInstanceOf(PaymentGateway::class, $resolved);
        self::assertSame($payment, $resolved);
    }

    #[Test]
    public function gateway_for_throws_when_resolved_gateway_is_not_a_payment_gateway(): void
    {
        $notPayment = $this->makeNonPaymentGateway('sms-orange');
        $generic = $this->makeGenericRegistry([$notPayment]);
        $typed = new PaymentGatewayRegistryImpl($generic);

        try {
            $typed->gatewayFor(ProviderCode::fromString('sms-orange'));
            self::fail('Expected UnsupportedPaymentGatewayException');
        } catch (UnsupportedPaymentGatewayException $e) {
            self::assertSame('sms-orange', $e->providerCode->toString());
            self::assertSame($notPayment::class, $e->resolvedClass);
            self::assertStringContainsString('PaymentGateway', $e->getMessage());
        }
    }

    #[Test]
    public function config_for_delegates_to_the_generic_registry(): void
    {
        $payment = $this->makePaymentGateway('my-coolpay');
        $generic = $this->makeGenericRegistry([$payment]);
        $typed = new PaymentGatewayRegistryImpl($generic);

        $config = $typed->configFor(ProviderCode::fromString('my-coolpay'));

        self::assertSame('my-coolpay', $config->code->toString());
    }

    #[Test]
    public function default_credentials_for_delegates_to_the_generic_registry(): void
    {
        $payment = $this->makePaymentGateway('my-coolpay');
        $generic = $this->makeGenericRegistry([$payment]);
        $typed = new PaymentGatewayRegistryImpl($generic);

        $credentials = $typed->defaultCredentialsFor(ProviderCode::fromString('my-coolpay'));

        self::assertSame('k', $credentials->apiKey);
    }

    #[Test]
    public function available_providers_returns_every_payment_gateway_from_the_generic_registry(): void
    {
        $a = $this->makePaymentGateway('mtn-momo');
        $b = $this->makePaymentGateway('cinetpay');
        $generic = $this->makeGenericRegistry([$a, $b]);
        $typed = new PaymentGatewayRegistryImpl($generic);

        $codes = array_map(
            static fn ($c) => $c->toString(),
            $typed->availableProviders(),
        );
        sort($codes);
        self::assertSame(['cinetpay', 'mtn-momo'], $codes);
    }

    #[Test]
    public function available_providers_excludes_gateways_that_are_not_payment_gateways(): void
    {
        // Review finding: the generic registry's availableProviders()
        // returns every provider platform-wide — payment AND
        // notification alike, since both share the same
        // 'provider.gateway' tag — so a bare delegation used to leak a
        // notification-only provider (e.g. WAHA/WhatsApp) into a
        // payment-scoped listing (the admin console's provider-
        // application dropdown).
        $payment = $this->makePaymentGateway('my-coolpay');
        $notification = $this->makeNonPaymentGateway('waha');
        $generic = $this->makeGenericRegistryWithMixedGateways([$payment, $notification]);
        $typed = new PaymentGatewayRegistryImpl($generic);

        $codes = array_map(
            static fn ($c) => $c->toString(),
            $typed->availableProviders(),
        );

        self::assertSame(['my-coolpay'], $codes);
    }

    private function makeProviderConfig(string $code = 'my-coolpay'): ProviderConfig
    {
        return ProviderConfig::fromArray([
            'code' => $code,
            'base_url' => 'https://example.com',
            'auth' => ['kind' => 'api-key'],
            'credentials' => ['api_key' => 'sk'],
            'timeouts' => [],
            'retries' => [],
            'breaker' => [],
        ]);
    }

    /**
     * A real `PaymentGateway` implementation for the happy path.
     */
    private function makePaymentGateway(string $code = 'my-coolpay'): PaymentGateway
    {
        $config = $this->makeProviderConfig($code);

        return new class($code, $config) implements PaymentGateway
        {
            public function __construct(
                private readonly string $code,
                private readonly ProviderConfig $config,
            ) {}

            public function providerCode(): ProviderCode
            {
                return ProviderCode::fromString($this->code);
            }

            public function config(): ProviderConfig
            {
                return $this->config;
            }

            public function initiate(InitiatePaymentCommand $command): PaymentReceipt
            {
                return new PaymentReceipt(
                    transactionRef: 'tx',
                    status: PaymentStatus::Pending,
                );
            }

            public function submitAction(PaymentAction $action): PaymentReceipt
            {
                return new PaymentReceipt(
                    transactionRef: $action->transactionRef,
                    status: PaymentStatus::Pending,
                );
            }

            public function checkStatus(string $transactionRef, string $projectId): PaymentReceipt
            {
                return new PaymentReceipt(
                    transactionRef: $transactionRef,
                    status: PaymentStatus::Succeeded,
                );
            }
        };
    }

    /**
     * A `ProviderGateway` that does NOT implement `PaymentGateway` —
     * simulates the case where a future domain (notification, sms, …)
     * tags a gateway that somehow ends up in the payment module's
     * query.
     */
    private function makeNonPaymentGateway(string $code = 'sms-orange'): ProviderGateway
    {
        return new class($code) implements ProviderGateway
        {
            public function __construct(private readonly string $code) {}

            public function providerCode(): ProviderCode
            {
                return ProviderCode::fromString($this->code);
            }

            public function config(): ProviderConfig
            {
                throw new LogicException('not used in this test');
            }
        };
    }

    /**
     * Same as `makeGenericRegistry()`, but for a mix of payment and
     * non-payment gateways — the domain label (`payment`/`notification`)
     * is purely informational to `ArrayProviderConfigRegistry` (ignored
     * by the loader), so each gateway just needs its own top-level key
     * to avoid overwriting another's config entry.
     *
     * @param list<ProviderGateway> $gateways
     */
    private function makeGenericRegistryWithMixedGateways(array $gateways): ProviderRegistry
    {
        $configs = [];
        foreach ($gateways as $i => $gateway) {
            $code = $gateway->providerCode()->toString();
            $configs['domain_'.$i][$code] = [
                'code' => $code,
                'base_url' => 'https://x',
                'auth' => ['kind' => 'api-key'],
                'credentials' => ['api_key' => 'k'],
                'timeouts' => [],
                'retries' => [],
                'breaker' => [],
            ];
        }
        $configRegistry = new ArrayProviderConfigRegistry($configs);

        return new class($gateways, $configRegistry) implements ProviderRegistry
        {
            /** @var array<string, ProviderGateway> */
            private readonly array $indexed;

            /**
             * @param list<ProviderGateway> $gateways
             */
            public function __construct(array $gateways, private readonly ProviderConfigRegistry $configs)
            {
                $byCode = [];
                foreach ($gateways as $gateway) {
                    $byCode[$gateway->providerCode()->toString()] = $gateway;
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

            /**
             * @return list<ProviderCode>
             */
            public function availableProviders(): array
            {
                return $this->configs->availableProviders();
            }
        };
    }

    /**
     * Builds a generic `ProviderRegistry` from a list of gateways and
     * a matching config registry.
     *
     * @param list<ProviderGateway> $gateways
     */
    private function makeGenericRegistry(array $gateways): ProviderRegistry
    {
        $configs = [];
        foreach ($gateways as $gateway) {
            $code = $gateway->providerCode()->toString();
            $configs['payment'][$code] = [
                'code' => $code,
                'base_url' => 'https://x',
                'auth' => ['kind' => 'api-key'],
                'credentials' => ['api_key' => 'k'],
                'timeouts' => [],
                'retries' => [],
                'breaker' => [],
            ];
        }
        $configRegistry = new ArrayProviderConfigRegistry($configs);

        return new class($gateways, $configRegistry) implements ProviderRegistry
        {
            /** @var array<string, ProviderGateway> */
            private readonly array $indexed;

            /**
             * @param list<ProviderGateway> $gateways
             */
            public function __construct(array $gateways, private readonly ProviderConfigRegistry $configs)
            {
                $byCode = [];
                foreach ($gateways as $gateway) {
                    $byCode[$gateway->providerCode()->toString()] = $gateway;
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

            /**
             * @return list<ProviderCode>
             */
            public function availableProviders(): array
            {
                $codes = [];
                foreach (array_keys($this->indexed) as $key) {
                    $codes[] = ProviderCode::fromString($key);
                }

                return $codes;
            }
        };
    }
}
