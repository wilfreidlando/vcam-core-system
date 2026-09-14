<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration;

use Core\Support\Integration\Credentials;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CredentialsTest extends TestCase
{
    #[Test]
    public function empty_credentials_have_no_fields(): void
    {
        $creds = Credentials::empty();

        self::assertNull($creds->apiKey);
        self::assertNull($creds->username);
        self::assertNull($creds->password);
        self::assertNull($creds->bearerToken);
        self::assertNull($creds->clientId);
        self::assertNull($creds->clientSecret);
    }

    #[Test]
    public function from_array_reads_known_fields(): void
    {
        $creds = Credentials::fromArray([
            'api_key' => 'ak-123',
            'client_id' => 'cid-456',
            'client_secret' => 'cs-789',
        ]);

        self::assertSame('ak-123', $creds->apiKey);
        self::assertSame('cid-456', $creds->clientId);
        self::assertSame('cs-789', $creds->clientSecret);
    }

    #[Test]
    public function from_array_ignores_unknown_fields_silently(): void
    {
        $creds = Credentials::fromArray([
            'api_key' => 'k',
            'unknown_field' => 'discarded',
        ]);

        self::assertSame('k', $creds->apiKey);
    }

    #[Test]
    public function merge_with_overrides_only_non_null_fields(): void
    {
        $base = new Credentials(
            apiKey: 'base-key',
            clientId: 'base-client',
            bearerToken: 'base-token',
        );

        $override = new Credentials(
            apiKey: 'override-key',
            clientSecret: 'override-secret',
        );

        $merged = $base->mergeWith($override);

        // Override wins for fields set in both.
        self::assertSame('override-key', $merged->apiKey);
        // Base preserved for fields not set in override.
        self::assertSame('base-client', $merged->clientId);
        self::assertSame('base-token', $merged->bearerToken);
        // Override adds new fields.
        self::assertSame('override-secret', $merged->clientSecret);
    }

    #[Test]
    public function merge_with_does_not_wipe_base_values_with_null(): void
    {
        $base = new Credentials(clientId: 'kept');

        $merged = $base->mergeWith(new Credentials);

        self::assertSame('kept', $merged->clientId);
    }

    #[Test]
    public function fingerprint_is_stable_across_instances_with_same_values(): void
    {
        $a = new Credentials(apiKey: 'ak', clientSecret: 'cs');
        $b = new Credentials(apiKey: 'ak', clientSecret: 'cs');

        self::assertSame($a->fingerprint(), $b->fingerprint());
    }

    #[Test]
    public function fingerprint_differs_when_values_differ(): void
    {
        $a = new Credentials(apiKey: 'ak');
        $b = new Credentials(apiKey: 'ak-different');

        self::assertNotSame($a->fingerprint(), $b->fingerprint());
    }

    #[Test]
    public function fingerprint_does_not_leak_plain_values(): void
    {
        $creds = new Credentials(apiKey: 'super-secret-key', clientSecret: 'very-private');

        $fingerprint = $creds->fingerprint();

        self::assertStringNotContainsString('super-secret-key', $fingerprint);
        self::assertStringNotContainsString('very-private', $fingerprint);
        self::assertSame(64, strlen($fingerprint)); // sha256 hex = 64 chars
    }
}
