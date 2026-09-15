<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Provider;

use Core\Support\Integration\Configuration\ArrayProviderConfigRegistry;
use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\Provider\DuplicateProviderCodeException;
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
    public function it_loads_provider_config_by_code(): void
    {
        $registry = new ArrayProviderConfigRegistry($this->sampleConfig());

        $config = $registry->configFor(ProviderCode::fromString('my-coolpay'));

        self::assertSame('my-coolpay', $config->code->toString());
        self::assertSame('https://my-coolpay.com', $config->baseUrl);
        self::assertSame('sk', $config->defaultCredentials->apiKey);
        self::assertSame('pub-1', $config->extra('public_key'));
    }

    #[Test]
    public function available_providers_lists_every_code_across_all_domains(): void
    {
        $registry = new ArrayProviderConfigRegistry($this->sampleConfig());

        $codes = array_map(
            static fn ($c) => $c->toString(),
            $registry->availableProviders(),
        );

        sort($codes);
        self::assertSame(['firebase', 'mtn-momo', 'my-coolpay'], $codes);
    }

    #[Test]
    public function has_returns_true_for_known_providers_and_false_otherwise(): void
    {
        $registry = new ArrayProviderConfigRegistry($this->sampleConfig());

        self::assertTrue($registry->has(ProviderCode::fromString('my-coolpay')));
        self::assertTrue($registry->has(ProviderCode::fromString('mtn-momo')));
        self::assertTrue($registry->has(ProviderCode::fromString('firebase')));
        self::assertFalse($registry->has(ProviderCode::fromString('unknown')));
    }

    #[Test]
    public function querying_an_unknown_provider_throws_provider_not_configured(): void
    {
        $registry = new ArrayProviderConfigRegistry($this->sampleConfig());

        $this->expectException(\Core\Support\Integration\Configuration\ProviderNotConfigured::class);
        $registry->configFor(ProviderCode::fromString('unknown'));
    }

    #[Test]
    public function default_credentials_accessor_returns_the_credentials_vo(): void
    {
        $registry = new ArrayProviderConfigRegistry($this->sampleConfig());

        $credentials = $registry->defaultCredentialsFor(ProviderCode::fromString('mtn-momo'));

        self::assertSame('tk', $credentials->bearerToken);
    }

    #[Test]
    public function the_top_level_environment_key_is_ignored(): void
    {
        $registry = new ArrayProviderConfigRegistry($this->sampleConfigWithEnvironment());

        $codes = array_map(
            static fn ($c) => $c->toString(),
            $registry->availableProviders(),
        );
        self::assertNotContains('environment', $codes);
    }

    #[Test]
    public function the_registry_works_with_an_empty_config(): void
    {
        $registry = new ArrayProviderConfigRegistry([]);

        self::assertSame([], $registry->availableProviders());
        self::assertFalse($registry->has(ProviderCode::fromString('anything')));
    }

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

    #[Test]
    public function two_gateways_with_the_same_code_throws_loudly_at_construction(): void
    {
        $a = $this->makeGateway(ProviderCode::fromString('orange'));
        $b = $this->makeGateway(ProviderCode::fromString('orange'));

        $this->expectException(DuplicateProviderCodeException::class);
        new TaggedProviderRegistry([$a, $b], new ArrayProviderConfigRegistry([]));
    }

    #[Test]
    public function duplicate_exception_carries_both_offending_gateways(): void
    {
        $a = $this->makeGateway(ProviderCode::fromString('orange'));
        $b = $this->makeGateway(ProviderCode::fromString('orange'));

        try {
            new TaggedProviderRegistry([$a, $b], new ArrayProviderConfigRegistry([]));
            self::fail('Expected DuplicateProviderCodeException');
        } catch (DuplicateProviderCodeException $e) {
            self::assertTrue($e->providerCode->equals(ProviderCode::fromString('orange')));
            self::assertSame($a, $e->first);
            self::assertSame($b, $e->second);
            self::assertStringContainsString('orange', $e->getMessage());
        }
    }

    #[Test]
    public function two_gateways_with_different_codes_are_indexed_independently(): void
    {
        $a = $this->makeGateway(ProviderCode::fromString('mtn-momo'));
        $b = $this->makeGateway(ProviderCode::fromString('cinetpay'));

        $configRegistry = new ArrayProviderConfigRegistry([]);

        $registry = new TaggedProviderRegistry([$a, $b], $configRegistry);

        self::assertSame($a, $registry->gatewayFor(ProviderCode::fromString('mtn-momo')));
        self::assertSame($b, $registry->gatewayFor(ProviderCode::fromString('cinetpay')));
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

    /**
     * @return array<string, mixed>
     */
    private function sampleConfig(): array
    {
        return [
            'payment' => [
                'my_coolpay' => [
                    'code' => 'my-coolpay',
                    'base_url' => 'https://my-coolpay.com',
                    'auth' => ['kind' => 'api-key'],
                    'credentials' => ['api_key' => 'sk'],
                    'timeouts' => [],
                    'retries' => [],
                    'breaker' => [],
                    'extras' => ['public_key' => 'pub-1'],
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
            'notification' => [
                'firebase' => [
                    'code' => 'firebase',
                    'base_url' => 'https://fcm.googleapis.com',
                    'auth' => ['kind' => 'bearer'],
                    'credentials' => ['bearer_token' => 'fk'],
                    'timeouts' => [],
                    'retries' => [],
                    'breaker' => [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleConfigWithEnvironment(): array
    {
        $config = $this->sampleConfig();
        $config['environment'] = 'sandbox';

        return $config;
    }
}
