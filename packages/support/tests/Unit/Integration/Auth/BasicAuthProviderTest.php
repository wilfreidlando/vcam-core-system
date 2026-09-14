<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Auth;

use Core\Support\Integration\Auth\BasicAuthProvider;
use Core\Support\Integration\Configuration\AuthStrategy;
use Core\Support\Integration\Credentials;
use Core\Support\Integration\Provider\ProviderCode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BasicAuthProviderTest extends TestCase
{
    private const PROVIDER = 'twilio';

    #[Test]
    public function produces_basic_authorization_header(): void
    {
        $provider = new BasicAuthProvider;

        $result = $provider->apply(
            code: ProviderCode::fromString(self::PROVIDER),
            strategy: AuthStrategy::fromArray(['kind' => 'basic']),
            credentials: new Credentials(username: 'alice', password: 's3cret'),
            existingHeaders: [],
            currentBody: null,
        );

        self::assertSame(
            'Basic '.base64_encode('alice:s3cret'),
            $result->headers['Authorization'],
        );
    }

    #[Test]
    public function existing_headers_are_preserved(): void
    {
        $provider = new BasicAuthProvider;

        $result = $provider->apply(
            code: ProviderCode::fromString(self::PROVIDER),
            strategy: AuthStrategy::fromArray(['kind' => 'basic']),
            credentials: new Credentials(username: 'u', password: 'p'),
            existingHeaders: ['Accept' => 'application/json', 'X-Custom' => 'kept'],
            currentBody: null,
        );

        self::assertSame('application/json', $result->headers['Accept']);
        self::assertSame('kept', $result->headers['X-Custom']);
        self::assertArrayHasKey('Authorization', $result->headers);
    }

    #[Test]
    public function missing_username_or_password_fails(): void
    {
        $provider = new BasicAuthProvider;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires username and password credentials');
        $provider->apply(
            code: ProviderCode::fromString(self::PROVIDER),
            strategy: AuthStrategy::fromArray(['kind' => 'basic']),
            credentials: new Credentials(password: 'only'),
            existingHeaders: [],
            currentBody: null,
        );
    }
}
