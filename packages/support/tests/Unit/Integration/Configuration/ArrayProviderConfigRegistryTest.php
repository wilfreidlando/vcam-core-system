<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Configuration;

use Core\Support\Integration\Configuration\ArrayProviderConfigRegistry;
use Core\Support\Integration\Configuration\ProviderNotConfigured;
use Core\Support\Integration\Provider\ProviderCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArrayProviderConfigRegistryTest extends TestCase
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

        $this->expectException(ProviderNotConfigured::class);
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
        $registry = new ArrayProviderConfigRegistry($this->sampleConfig(withEnvironment: true));

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

    /**
     * @return array<string, array<string, mixed>|string>
     */
    private function sampleConfig(bool $withEnvironment = false): array
    {
        /** @var array<string, array<string, mixed>> $config */
        $config = [
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

        if ($withEnvironment) {
            $config['environment'] = 'sandbox';
        }

        return $config;
    }
}
