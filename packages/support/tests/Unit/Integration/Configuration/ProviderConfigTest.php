<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Configuration;

use Core\Support\Integration\Configuration\ProviderConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProviderConfigTest extends TestCase
{
    #[Test]
    public function minimal_config_loads_with_defaults(): void
    {
        $config = ProviderConfig::fromArray($this->minimalConfig());

        self::assertSame('mtn-momo', $config->code->toString());
        self::assertSame('https://example.com', $config->baseUrl);
        self::assertTrue($config->enabled);
        self::assertFalse($config->debug);
    }

    #[Test]
    public function base_url_is_trimmed_of_trailing_slash(): void
    {
        $data = $this->minimalConfig();
        $data['base_url'] = 'https://example.com/';

        $config = ProviderConfig::fromArray($data);

        self::assertSame('https://example.com', $config->baseUrl);
    }

    #[Test]
    public function enabled_false_is_kill_switch(): void
    {
        $data = $this->minimalConfig();
        $data['enabled'] = false;

        $config = ProviderConfig::fromArray($data);

        self::assertFalse($config->enabled);
    }

    #[Test]
    public function default_credentials_are_optional(): void
    {
        $config = ProviderConfig::fromArray($this->minimalConfig());

        self::assertNull($config->defaultCredentials->apiKey);
        self::assertNull($config->defaultCredentials->clientId);
    }

    #[Test]
    public function credentials_are_loaded_when_provided(): void
    {
        $data = $this->minimalConfig();
        $data['credentials'] = ['api_key' => 'sk-abc'];

        $config = ProviderConfig::fromArray($data);

        self::assertSame('sk-abc', $config->defaultCredentials->apiKey);
    }

    #[Test]
    public function a_missing_required_field_is_rejected(): void
    {
        $data = $this->minimalConfig();
        unset($data['auth']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ProviderConfig.auth is required.');
        ProviderConfig::fromArray($data);
    }

    #[Test]
    public function extra_returns_the_value_when_set(): void
    {
        $data = $this->minimalConfig();
        $data['extras'] = ['subscription_key' => 'sub-1', 'site_id' => 'site-1'];

        $config = ProviderConfig::fromArray($data);

        self::assertSame('sub-1', $config->extra('subscription_key'));
        self::assertSame('site-1', $config->extra('site_id'));
    }

    #[Test]
    public function extra_returns_the_default_when_not_set(): void
    {
        $config = ProviderConfig::fromArray($this->minimalConfig());

        self::assertSame('fallback', $config->extra('missing', 'fallback'));
        self::assertNull($config->extra('missing'));
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalConfig(): array
    {
        return [
            'code' => 'mtn-momo',
            'base_url' => 'https://example.com',
            'auth' => ['kind' => 'bearer'],
            'timeouts' => [],
            'retries' => [],
            'breaker' => [],
        ];
    }
}
