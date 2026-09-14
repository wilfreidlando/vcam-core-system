<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Configuration;

use Core\Support\Integration\Configuration\ApiKeyPlacement;
use Core\Support\Integration\Configuration\AuthKind;
use Core\Support\Integration\Configuration\AuthStrategy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuthStrategyTest extends TestCase
{
    #[Test]
    public function api_key_header_uses_default_header(): void
    {
        $strategy = AuthStrategy::fromArray(['kind' => 'api-key']);

        self::assertSame(AuthKind::ApiKey, $strategy->kind);
        self::assertNull($strategy->apiKeyHeader);
        self::assertSame(ApiKeyPlacement::Header, $strategy->apiKeyPlacement);
    }

    #[Test]
    public function api_key_header_can_be_customised(): void
    {
        $strategy = AuthStrategy::fromArray([
            'kind' => 'api-key',
            'api_key_header' => 'Ocp-Apim-Subscription-Key',
        ]);

        self::assertSame('Ocp-Apim-Subscription-Key', $strategy->apiKeyHeader);
    }

    #[Test]
    public function api_key_can_be_placed_in_body(): void
    {
        $strategy = AuthStrategy::fromArray([
            'kind' => 'api-key',
            'api_key_placement' => 'body',
            'api_key_body_field' => 'apiKey',
        ]);

        self::assertSame(ApiKeyPlacement::Body, $strategy->apiKeyPlacement);
        self::assertSame('apiKey', $strategy->apiKeyBodyField);
    }

    #[Test]
    public function an_unknown_kind_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown AuthStrategy.kind');
        AuthStrategy::fromArray(['kind' => 'magic-token']);
    }

    #[Test]
    public function a_missing_kind_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AuthStrategy::fromArray([]);
    }

    #[Test]
    public function an_unknown_placement_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown AuthStrategy.api_key_placement');
        AuthStrategy::fromArray([
            'kind' => 'api-key',
            'api_key_placement' => 'cookie',
        ]);
    }
}
