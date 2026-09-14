<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Redaction;

use Core\Support\Integration\Redaction\PayloadRedactor;
use Core\Support\Integration\Redaction\SensitiveKeySet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PayloadRedactorTest extends TestCase
{
    #[Test]
    public function top_level_authorization_is_redacted(): void
    {
        $redactor = new PayloadRedactor(new SensitiveKeySet);

        /** @var array<string, mixed> $redacted */
        $redacted = $redactor->redact([
            'authorization' => 'Bearer secret-token',
            'name' => 'kept',
        ]);

        self::assertSame('***REDACTED***', $redacted['authorization']);
        self::assertSame('kept', $redacted['name']);
    }

    #[Test]
    public function nested_secrets_are_redacted(): void
    {
        $redactor = new PayloadRedactor(new SensitiveKeySet);

        /** @var array<string, mixed> $redacted */
        $redacted = $redactor->redact([
            'data' => [
                'user' => [
                    'name' => 'alice',
                    'password' => 'shh',
                    'client_secret' => 'cs-1',
                ],
            ],
        ]);

        /** @var array<string, mixed> $data */
        $data = $redacted['data'];
        /** @var array<string, mixed> $user */
        $user = $data['user'];
        self::assertSame('alice', $user['name']);
        self::assertSame('***REDACTED***', $user['password']);
        self::assertSame('***REDACTED***', $user['client_secret']);
    }

    #[Test]
    public function header_redaction_is_case_insensitive(): void
    {
        $redactor = new PayloadRedactor(new SensitiveKeySet);

        $redacted = $redactor->redactHeaders([
            'Authorization' => 'Bearer x',
            'X-Api-Key' => 'k',
            'X-Token' => 't',
            'Accept' => 'application/json',
        ]);

        self::assertSame('***REDACTED***', $redacted['Authorization']);
        self::assertSame('***REDACTED***', $redacted['X-Api-Key']);
        self::assertSame('***REDACTED***', $redacted['X-Token']);
        self::assertSame('application/json', $redacted['Accept']);
    }

    #[Test]
    public function camelcase_secret_keys_match_via_substring(): void
    {
        $redactor = new PayloadRedactor(new SensitiveKeySet);

        /** @var array<string, mixed> $redacted */
        $redacted = $redactor->redact([
            'apiSecret' => 'shh',
            'accessToken' => 'shh',
            'clientSecret' => 'shh',
            'confirmationPin' => 'shh',
            'confirmationPassword' => 'shh',
            'name' => 'kept',
        ]);

        self::assertSame('***REDACTED***', $redacted['apiSecret']);
        self::assertSame('***REDACTED***', $redacted['accessToken']);
        self::assertSame('***REDACTED***', $redacted['clientSecret']);
        self::assertSame('***REDACTED***', $redacted['confirmationPin']);
        self::assertSame('***REDACTED***', $redacted['confirmationPassword']);
        self::assertSame('kept', $redacted['name']);
    }

    #[Test]
    public function redaction_is_idempotent(): void
    {
        $redactor = new PayloadRedactor(new SensitiveKeySet);

        $first = $redactor->redact(['password' => 'shh']);
        $second = $redactor->redact($first);

        self::assertSame($first, $second);
    }

    #[Test]
    public function extra_provider_specific_keys_are_redacted(): void
    {
        $keys = new SensitiveKeySet(extraHeaderNames: ['X-Tenant-Token']);
        $redactor = new PayloadRedactor($keys);

        $redacted = $redactor->redactHeaders([
            'X-Tenant-Token' => 'tenant-1',
        ]);

        self::assertSame('***REDACTED***', $redacted['X-Tenant-Token']);
    }

    #[Test]
    public function non_array_payload_passes_through_unchanged(): void
    {
        $redactor = new PayloadRedactor(new SensitiveKeySet);

        self::assertSame('a string', $redactor->redact('a string'));
        self::assertSame(42, $redactor->redact(42));
        self::assertNull($redactor->redact(null));
    }
}
