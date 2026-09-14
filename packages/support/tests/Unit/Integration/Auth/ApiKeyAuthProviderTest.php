<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Auth;

use Core\Support\Integration\Auth\ApiKeyAuthProvider;
use Core\Support\Integration\Configuration\ApiKeyPlacement;
use Core\Support\Integration\Configuration\AuthKind;
use Core\Support\Integration\Configuration\AuthStrategy;
use Core\Support\Integration\Credentials;
use Core\Support\Integration\Provider\ProviderCode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApiKeyAuthProviderTest extends TestCase
{
    private const PROVIDER = 'mtn-momo';

    #[Test]
    public function only_supports_api_key_kind(): void
    {
        $provider = new ApiKeyAuthProvider;

        self::assertTrue($provider->supports(AuthKind::ApiKey));
        self::assertFalse($provider->supports(AuthKind::Basic));
        self::assertFalse($provider->supports(AuthKind::Bearer));
    }

    #[Test]
    public function api_key_in_default_header(): void
    {
        $provider = new ApiKeyAuthProvider;
        $code = ProviderCode::fromString(self::PROVIDER);

        $result = $provider->apply(
            code: $code,
            strategy: AuthStrategy::fromArray(['kind' => 'api-key']),
            credentials: new Credentials(apiKey: 'sk-abc'),
            existingHeaders: ['Accept' => 'application/json'],
            currentBody: null,
        );

        self::assertSame('sk-abc', $result->headers['X-Api-Key']);
        self::assertSame('application/json', $result->headers['Accept']);
        self::assertNull($result->body);
    }

    #[Test]
    public function api_key_in_custom_header(): void
    {
        $provider = new ApiKeyAuthProvider;

        $result = $provider->apply(
            code: ProviderCode::fromString(self::PROVIDER),
            strategy: AuthStrategy::fromArray([
                'kind' => 'api-key',
                'api_key_header' => 'Ocp-Apim-Subscription-Key',
            ]),
            credentials: new Credentials(apiKey: 'sub-xyz'),
            existingHeaders: [],
            currentBody: null,
        );

        self::assertSame('sub-xyz', $result->headers['Ocp-Apim-Subscription-Key']);
        self::assertArrayNotHasKey('X-Api-Key', $result->headers);
    }

    #[Test]
    public function api_key_in_body(): void
    {
        $provider = new ApiKeyAuthProvider;

        $result = $provider->apply(
            code: ProviderCode::fromString(self::PROVIDER),
            strategy: AuthStrategy::fromArray([
                'kind' => 'api-key',
                'api_key_placement' => 'body',
                'api_key_body_field' => 'apiKey',
            ]),
            credentials: new Credentials(apiKey: 'sk-in-body'),
            existingHeaders: [],
            currentBody: ['orderId' => 'O-1'],
        );

        self::assertIsArray($result->body);
        self::assertSame('sk-in-body', $result->body['apiKey']);
        self::assertSame('O-1', $result->body['orderId']);
        self::assertArrayNotHasKey('X-Api-Key', $result->headers);
    }

    #[Test]
    public function missing_api_key_credential_fails(): void
    {
        $provider = new ApiKeyAuthProvider;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a non-empty api_key credential');
        $provider->apply(
            code: ProviderCode::fromString(self::PROVIDER),
            strategy: AuthStrategy::fromArray(['kind' => 'api-key']),
            credentials: new Credentials,
            existingHeaders: [],
            currentBody: null,
        );
    }

    #[Test]
    public function empty_api_key_credential_fails(): void
    {
        $provider = new ApiKeyAuthProvider;

        $this->expectException(InvalidArgumentException::class);
        $provider->apply(
            code: ProviderCode::fromString(self::PROVIDER),
            strategy: AuthStrategy::fromArray(['kind' => 'api-key']),
            credentials: new Credentials(apiKey: ''),
            existingHeaders: [],
            currentBody: null,
        );
    }

    #[Test]
    public function body_placement_without_body_field_configured_fails(): void
    {
        $provider = new ApiKeyAuthProvider;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('api_key_body_field is missing');
        $provider->apply(
            code: ProviderCode::fromString(self::PROVIDER),
            strategy: new AuthStrategy(
                kind: AuthKind::ApiKey,
                apiKeyPlacement: ApiKeyPlacement::Body,
                apiKeyBodyField: null,
            ),
            credentials: new Credentials(apiKey: 'k'),
            existingHeaders: [],
            currentBody: ['x' => 1],
        );
    }

    #[Test]
    public function body_placement_with_non_array_body_fails(): void
    {
        $provider = new ApiKeyAuthProvider;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the body is not an array');
        $provider->apply(
            code: ProviderCode::fromString(self::PROVIDER),
            strategy: AuthStrategy::fromArray([
                'kind' => 'api-key',
                'api_key_placement' => 'body',
                'api_key_body_field' => 'apiKey',
            ]),
            credentials: new Credentials(apiKey: 'k'),
            existingHeaders: [],
            currentBody: 'string-body',
        );
    }
}
