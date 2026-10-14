<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Integration\Infrastructure\Wiring;

use Core\Payment\Contracts\PaymentGateway;
use Core\Payment\Contracts\PaymentGatewayRegistry;
use Core\Payment\Infrastructure\Provider\MyCoolPay\MyCoolPayAdapter;
use Core\Payment\Infrastructure\Provider\MyCoolPay\MyCoolPayStatusMapper;
use Core\Payment\Infrastructure\Provider\PaymentGatewayRegistryImpl;
use Core\Support\Integration\Configuration\ArrayProviderConfigRegistry;
use Core\Support\Integration\Configuration\ProviderConfigRegistry;
use Core\Support\Integration\ExternalApiCaller;
use Core\Support\Integration\Provider\ProviderCode;
use Core\Support\Integration\Provider\ProviderGateway;
use Core\Support\Integration\Provider\ProviderRegistry;
use Core\Support\Integration\Provider\UnsupportedProviderException;
use Illuminate\Foundation\Application;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end wiring test: bootstraps the full Laravel application
 * (with the foundation + payment service providers registered) and
 * asserts that:
 *   - the MyCoolPayAdapter is registered in the container
 *   - the ProviderRegistry resolves it by code
 *   - the loaded config reaches the adapter (baseUrl, public_key, etc.)
 *
 * The test reads from `config/providers.php` — the canonical
 * configuration file at the project root.
 *
 * NOTE: we rely on the application shell having wired the config
 * repository (via `phpunit.xml`'s bootstrap, see `bootstrap/app.php`).
 * The test does NOT build a separate `Application` — that would conflict
 * with the parallel-testing machinery of the test framework.
 */
final class ProviderWiringTest extends TestCase
{
    #[Test]
    public function the_provider_registry_resolves_the_mycoolpay_gateway(): void
    {
        $registry = $this->app->make(ProviderRegistry::class);
        self::assertInstanceOf(\Core\Support\Integration\Provider\TaggedProviderRegistry::class, $registry);

        $gateway = $registry->gatewayFor(ProviderCode::fromString('my-coolpay'));

        self::assertInstanceOf(MyCoolPayAdapter::class, $gateway);
    }

    #[Test]
    public function the_gateway_is_indexed_under_its_provider_code(): void
    {
        $registry = $this->app->make(ProviderRegistry::class);

        $codes = array_map(
            static fn ($c) => $c->toString(),
            $registry->availableProviders(),
        );

        self::assertContains('my-coolpay', $codes);
    }

    #[Test]
    public function the_mycoolpay_adapter_is_wired_with_its_config_from_providers_php(): void
    {
        $registry = $this->app->make(ProviderRegistry::class);
        $gateway = $registry->gatewayFor(ProviderCode::fromString('my-coolpay'));

        $config = $gateway->config();
        self::assertSame('my-coolpay', $config->code->toString());
        self::assertSame('https://my-coolpay.com', $config->baseUrl);
        self::assertTrue($config->enabled);
        self::assertSame('pub-default-public-key', $config->extra('public_key'));
        self::assertSame('sk-default-private-key', $config->defaultCredentials->apiKey);
    }

    #[Test]
    public function the_mycoolpay_status_mapper_is_resolvable_from_the_container(): void
    {
        $mapper = $this->app->make(MyCoolPayStatusMapper::class);

        self::assertInstanceOf(MyCoolPayStatusMapper::class, $mapper);
    }

    #[Test]
    public function the_external_api_caller_is_resolvable_from_the_container(): void
    {
        $caller = $this->app->make(ExternalApiCaller::class);

        self::assertInstanceOf(ExternalApiCaller::class, $caller);
    }

    #[Test]
    public function the_typed_payment_gateway_registry_resolves_mycoolpay_as_a_payment_gateway(): void
    {
        // Use cases in the payment module depend on PaymentGatewayRegistry,
        // never on the generic ProviderRegistry. The wrapping casts the
        // generic to the payment-specific interface so call sites don't
        // have to do `instanceof` themselves.
        $typed = $this->app->make(PaymentGatewayRegistry::class);
        $gateway = $typed->gatewayFor(ProviderCode::fromString('my-coolpay'));

        self::assertInstanceOf(PaymentGateway::class, $gateway);
        self::assertInstanceOf(MyCoolPayAdapter::class, $gateway);
    }

    #[Test]
    public function the_typed_registry_raises_a_clear_error_for_a_non_payment_gateway(): void
    {
        // We can't easily inject a non-PaymentGateway into the tagged
        // iterable (the tag happens at boot), so we exercise the cast
        // directly via a hand-built typed registry.
        $notPaymentGateway = new class implements ProviderGateway
        {
            public function providerCode(): ProviderCode
            {
                return ProviderCode::fromString('notification-orange');
            }

            public function config(): \Core\Support\Integration\Configuration\ProviderConfig
            {
                throw new LogicException('not used');
            }
        };

        $generic = new class([$notPaymentGateway], new ArrayProviderConfigRegistry([])) implements ProviderRegistry
        {
            /** @var array<string, ProviderGateway> */
            private array $indexed;

            /**
             * @param list<ProviderGateway> $gateways
             */
            public function __construct(array $gateways, private ProviderConfigRegistry $c)
            {
                $this->indexed = [];
                foreach ($gateways as $g) {
                    $this->indexed[$g->providerCode()->toString()] = $g;
                }
            }

            public function gatewayFor(ProviderCode $code): ProviderGateway
            {
                if (! isset($this->indexed[$code->toString()])) {
                    throw new UnsupportedProviderException($code);
                }

                return $this->indexed[$code->toString()];
            }

            public function configFor(ProviderCode $c): \Core\Support\Integration\Configuration\ProviderConfig
            {
                return $this->c->configFor($c);
            }

            public function defaultCredentialsFor(ProviderCode $c): \Core\Support\Integration\Credentials
            {
                return $this->c->defaultCredentialsFor($c);
            }

            public function availableProviders(): array
            {
                $codes = [];
                foreach (array_keys($this->indexed) as $key) {
                    $codes[] = ProviderCode::fromString($key);
                }

                return $codes;
            }
        };

        $typed = new PaymentGatewayRegistryImpl($generic);

        $this->expectException(\Core\Payment\Contracts\UnsupportedPaymentGatewayException::class);
        $typed->gatewayFor(ProviderCode::fromString('notification-orange'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        // config/providers.php is loaded once, at app boot, before
        // setUp runs. We override the loader the foundation uses so the
        // test gets explicit credential values — production never relies
        // on the empty defaults.
        //
        // Every module's `provider.gateway`-tagged adapter shares this
        // one config-backed registry (ADR-0039/ADR-0041 §2), so a
        // minimal fixture here must configure ALL of them, not just
        // `my-coolpay` — `TaggedProviderRegistry`'s constructor resolves
        // every tagged gateway (including `WahaAdapter`, ADR-0042) to
        // index it, and each adapter's own binding closure reads its
        // config eagerly at that point.
        $this->app->singleton(
            'providers.config_loader',
            fn () => fn (): array => [
                'payment' => [
                    'my_coolpay' => [
                        'code' => 'my-coolpay',
                        'base_url' => 'https://my-coolpay.com',
                        'auth' => ['kind' => 'api-key'],
                        'credentials' => ['api_key' => 'sk-default-private-key'],
                        'timeouts' => [],
                        'retries' => [],
                        'breaker' => [],
                        'extras' => ['public_key' => 'pub-default-public-key'],
                    ],
                ],
                'notification' => [
                    'whatsapp_waha' => [
                        'code' => 'whatsapp-waha',
                        'base_url' => 'https://waha.test',
                        'auth' => ['kind' => 'api-key', 'api_key_header' => 'X-Api-Key'],
                        'credentials' => ['api_key' => 'waha-test-key'],
                        'timeouts' => [],
                        'retries' => [],
                        'breaker' => [],
                        'extras' => [],
                    ],
                ],
            ],
        );

        // Re-resolve the providers config registry so it picks up our
        // injected loader.
        $this->app->forgetInstance(ProviderConfigRegistry::class);
        $this->app->forgetInstance(ProviderRegistry::class);
    }
}
