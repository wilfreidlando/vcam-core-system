<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Provider;

use Core\Support\Integration\Configuration\ArrayProviderConfigRegistry;
use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\Provider\ProviderCode;
use Core\Support\Integration\Provider\ProviderGateway;
use Core\Support\Integration\Provider\TaggedProviderRegistry;
use Core\Support\Integration\Provider\UnsupportedProviderException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaggedProviderRegistryTest extends TestCase
{
    #[Test]
    public function it_indexes_gateways_by_their_code(): void
    {
        $gateways = [
            $this->makeGateway(ProviderCode::fromString('my-coolpay')),
            $this->makeGateway(ProviderCode::fromString('mtn-momo')),
        ];

        $configRegistry = new ArrayProviderConfigRegistry([
            'payment' => [
                'my_coolpay' => [
                    'code' => 'my-coolpay',
                    'base_url' => 'https://my-coolpay.com',
                    'auth' => ['kind' => 'api-key'],
                    'credentials' => ['api_key' => 'sk'],
                    'timeouts' => [],
                    'retries' => [],
                    'breaker' => [],
                ],
                'mtn_momo' => [
                    'code' => 'mtn-momo',
                    'base_url' => 'https://momo.mtn.com',
                    'auth' => ['kind' => 'bearer'],
                    'credentials' => ['bearer_token' => 'tk'],
                    'timeouts' => [],
                    'retries' => [],
                    'breaker' => [],
                ],
            ],
        ]);

        $registry = new TaggedProviderRegistry($gateways, $configRegistry);

        self::assertSame($gateways[0], $registry->gatewayFor(ProviderCode::fromString('my-coolpay')));
        self::assertSame($gateways[1], $registry->gatewayFor(ProviderCode::fromString('mtn-momo')));
    }

    #[Test]
    public function unknown_code_throws_unsupported_provider(): void
    {
        $registry = new TaggedProviderRegistry(
            gateways: [],
            configs: new ArrayProviderConfigRegistry([]),
        );

        $this->expectException(UnsupportedProviderException::class);
        $registry->gatewayFor(ProviderCode::fromString('unknown'));
    }

    #[Test]
    public function config_for_delegates_to_the_config_registry(): void
    {
        $configRegistry = new ArrayProviderConfigRegistry([
            'payment' => [
                'my_coolpay' => [
                    'code' => 'my-coolpay',
                    'base_url' => 'https://x',
                    'auth' => ['kind' => 'api-key'],
                    'credentials' => ['api_key' => 'k'],
                    'timeouts' => [],
                    'retries' => [],
                    'breaker' => [],
                ],
            ],
        ]);

        $registry = new TaggedProviderRegistry([], $configRegistry);

        $config = $registry->configFor(ProviderCode::fromString('my-coolpay'));
        self::assertSame('https://x', $config->baseUrl);
    }

    #[Test]
    public function available_providers_delegates_to_the_config_registry(): void
    {
        $configRegistry = new ArrayProviderConfigRegistry([
            'payment' => [
                'my_coolpay' => [
                    'code' => 'my-coolpay', 'base_url' => 'x',
                    'auth' => ['kind' => 'api-key'], 'credentials' => ['api_key' => 'k'],
                    'timeouts' => [], 'retries' => [], 'breaker' => [],
                ],
            ],
            'notification' => [
                'firebase' => [
                    'code' => 'firebase', 'base_url' => 'y',
                    'auth' => ['kind' => 'bearer'], 'credentials' => ['bearer_token' => 'k'],
                    'timeouts' => [], 'retries' => [], 'breaker' => [],
                ],
            ],
        ]);

        $registry = new TaggedProviderRegistry([], $configRegistry);

        $codes = array_map(
            static fn ($c) => $c->toString(),
            $registry->availableProviders(),
        );
        sort($codes);
        self::assertSame(['firebase', 'my-coolpay'], $codes);
    }

    #[Test]
    public function default_credentials_for_delegates_to_the_config_registry(): void
    {
        $configRegistry = new ArrayProviderConfigRegistry([
            'payment' => [
                'mtn_momo' => [
                    'code' => 'mtn-momo', 'base_url' => 'x',
                    'auth' => ['kind' => 'bearer'], 'credentials' => ['bearer_token' => 'k'],
                    'timeouts' => [], 'retries' => [], 'breaker' => [],
                ],
            ],
        ]);

        $registry = new TaggedProviderRegistry([], $configRegistry);

        $credentials = $registry->defaultCredentialsFor(ProviderCode::fromString('mtn-momo'));
        self::assertSame('k', $credentials->bearerToken);
    }

    private function makeGateway(ProviderCode $code): ProviderGateway
    {
        return new class($code) implements ProviderGateway
        {
            public function __construct(private readonly ProviderCode $code) {}

            public function providerCode(): ProviderCode
            {
                return $this->code;
            }

            public function config(): ProviderConfig
            {
                throw new LogicException('Not used in this test.');
            }
        };
    }
}
