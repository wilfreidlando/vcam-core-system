<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Auth;

use Core\Support\Integration\Auth\BearerAuthProvider;
use Core\Support\Integration\Configuration\AuthStrategy;
use Core\Support\Integration\Credentials;
use Core\Support\Integration\Provider\ProviderCode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BearerAuthProviderTest extends TestCase
{
    private const PROVIDER = 'firebase';

    #[Test]
    public function only_supports_bearer_kind(): void
    {
        $provider = new BearerAuthProvider;

        self::assertTrue($provider->supports(\Core\Support\Integration\Configuration\AuthKind::Bearer));
        self::assertFalse($provider->supports(\Core\Support\Integration\Configuration\AuthKind::ApiKey));
        self::assertFalse($provider->supports(\Core\Support\Integration\Configuration\AuthKind::Basic));
    }

    #[Test]
    public function produces_bearer_authorization_header(): void
    {
        $provider = new BearerAuthProvider;

        $result = $provider->apply(
            code: ProviderCode::fromString(self::PROVIDER),
            strategy: AuthStrategy::fromArray(['kind' => 'bearer']),
            credentials: new Credentials(bearerToken: 'firebase-server-key'),
            existingHeaders: [],
            currentBody: null,
        );

        self::assertSame('Bearer firebase-server-key', $result->headers['Authorization']);
        self::assertNull($result->body);
    }

    #[Test]
    public function missing_bearer_token_fails(): void
    {
        $provider = new BearerAuthProvider;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a non-empty bearer_token credential');
        $provider->apply(
            code: ProviderCode::fromString(self::PROVIDER),
            strategy: AuthStrategy::fromArray(['kind' => 'bearer']),
            credentials: new Credentials,
            existingHeaders: [],
            currentBody: null,
        );
    }
}
