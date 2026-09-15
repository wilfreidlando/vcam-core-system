<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Integration\Infrastructure\Wiring;

use Core\Payment\Infrastructure\Provider\MyCoolPay\MyCoolPayAdapter;
use Core\Payment\Infrastructure\Provider\MyCoolPay\MyCoolPayStatusMapper;
use Core\Support\Integration\ExternalApiCaller;
use Core\Support\Integration\Provider\ProviderCode;
use Core\Support\Integration\Provider\ProviderRegistry;
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
 * configuration file at the project root. Changes to that file
 * propagate here.
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
}
