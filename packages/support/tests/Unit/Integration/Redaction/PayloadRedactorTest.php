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
    public function mycoolpays_private_key_header_is_redacted(): void
    {
        // Security review finding: this header was missing from the
        // list entirely — a provider debug flag (MYCOOLPAY_DEBUG) would
        // have logged the live private key in cleartext.
        $redactor = new PayloadRedactor(new SensitiveKeySet);

        $redacted = $redactor->redactHeaders([
            'X-PRIVATE-KEY' => 'sk_live_should_never_appear_in_logs',
        ]);

        self::assertSame('***REDACTED***', $redacted['X-PRIVATE-KEY']);
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

    #[Test]
    public function personal_data_is_redacted(): void
    {
        // ADR-0093 §6 / ADR-0081 S-17 — a name, an address and a phone
        // number are personal data: the Core keeps them in clear
        // (ADR-0123), and they must never be written in clear in a log.
        $redactor = new PayloadRedactor(new SensitiveKeySet);

        /** @var array<string, mixed> $redacted */
        $redacted = $redactor->redact([
            'display_name' => 'Marie Ngo Bell',
            'email' => 'marie@example.cm',
            'phone' => '+237699887766',
            'variables' => ['phone_number' => '+237699887766'],
            'plan_code' => 'mensuel',
        ]);

        self::assertSame('***REDACTED***', $redacted['display_name']);
        self::assertSame('***REDACTED***', $redacted['email']);
        self::assertSame('***REDACTED***', $redacted['phone']);
        self::assertSame('mensuel', $redacted['plan_code']);

        /** @var array<string, mixed> $variables */
        $variables = $redacted['variables'];
        self::assertSame('***REDACTED***', $variables['phone_number']);
    }

    #[Test]
    public function one_time_codes_are_redacted_but_diagnostic_codes_are_kept(): void
    {
        $redactor = new PayloadRedactor(new SensitiveKeySet);

        /** @var array<string, mixed> $redacted */
        $redacted = $redactor->redact([
            // Audit 2026-10-29 #2 — the console's MFA field is named `code`.
            'code' => '123456',
            'otp' => '654321',
            'mfa_code' => '111111',
            'sms_code' => '222222',
            'verification_code' => '333333',
            'totp' => '444444',
            'Code' => '555555',
            'nested' => ['code' => '666666'],
            // Not secrets: an operator needs them in a log line.
            'status_code' => 502,
            'provider_code' => 'my-coolpay',
            'error_code' => 'E42',
            'postal_code' => 'BP 12',
            'footprint' => 'kept',
        ]);

        foreach (['code', 'otp', 'mfa_code', 'sms_code', 'verification_code', 'totp', 'Code'] as $key) {
            self::assertSame('***REDACTED***', $redacted[$key], $key);
        }
        self::assertSame(['code' => '***REDACTED***'], $redacted['nested']);
        self::assertSame(502, $redacted['status_code']);
        self::assertSame('my-coolpay', $redacted['provider_code']);
        self::assertSame('E42', $redacted['error_code']);
        self::assertSame('BP 12', $redacted['postal_code']);
        self::assertSame('kept', $redacted['footprint']);
    }

    #[Test]
    public function provider_signatures_and_macs_are_redacted(): void
    {
        $redactor = new PayloadRedactor(new SensitiveKeySet);

        /** @var array<string, mixed> $redacted */
        $redacted = $redactor->redact([
            'signature' => md5('secret'),
            'mac' => 'abc',
            'hmac' => 'def',
            'payload_checksum' => 'ghi',
            'machine' => 'kept',
            'transaction_ref' => 'tx-1',
        ]);

        foreach (['signature', 'mac', 'hmac', 'payload_checksum'] as $key) {
            self::assertSame('***REDACTED***', $redacted[$key], $key);
        }
        self::assertSame('kept', $redacted['machine']);
        self::assertSame('tx-1', $redacted['transaction_ref']);
    }

    #[Test]
    public function the_customers_own_name_and_the_search_terms_are_redacted(): void
    {
        // Audit 2026-10-29 #9 / #19 — the customer's name travels in the
        // payment payload and the operator's search term in the query
        // string: both are personal data (ADR-0083 §1) and both were
        // logged in clear.
        $redactor = new PayloadRedactor(new SensitiveKeySet);

        /** @var array<string, mixed> $redacted */
        $redacted = $redactor->redact([
            'customer_name' => 'Marie Ngo Bell',
            'contact_name' => 'Marie Ngo Bell',
            'search' => 'marie@example.cm',
            'q' => '+237699887766',
            // Not personal data: the operator needs these in a log line.
            'plan_name' => 'Mensuel',
            'provider_name' => 'my-coolpay',
            'method_name' => 'card',
        ]);

        foreach (['customer_name', 'contact_name', 'search', 'q'] as $key) {
            self::assertSame('***REDACTED***', $redacted[$key], $key);
        }
        self::assertSame('Mensuel', $redacted['plan_name']);
        self::assertSame('my-coolpay', $redacted['provider_name']);
        self::assertSame('card', $redacted['method_name']);
    }
}
