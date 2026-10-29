<?php

declare(strict_types=1);

namespace Core\Support\Integration\Redaction;

/**
 * Default set of HTTP header / JSON-key names whose values must NEVER
 * appear in logs.
 *
 * Two complementary dimensions:
 *  - **Header names**: matched case-insensitively against the keys of the
 *    response/request header bag.
 *  - **Key substrings**: matched case-insensitively against the
 *    dot-separated path of any value in the structured payload (e.g.
 *    `data.user.password`, `meta.refresh_token`).
 *
 * The set is intentionally a **closed list**, not a regex pattern:
 * over-permissive patterns (`.*secret.*`) match too much and either
 *    under-redact (false sense of safety) or over-redact (operators
 *    lose signal). Each entry here is reviewed.
 *
 * Adapters can extend this set via the `extraKeys` parameter of
 * `PayloadRedactor` (e.g. for provider-specific header names like
 * `Ocp-Apim-Subscription-Key`).
 */
final class SensitiveKeySet
{
    /** @var list<string> */
    public const HEADER_NAMES = [
        'authorization',
        'proxy-authorization',
        'x-api-key',
        'api-key',
        'x-token',
        'x-auth-token',
        'auth-token',
        'x-access-token',
        'access-token',
        'x-secret',
        'x-signature',
        'signature',
        // MyCoolPay's private-key header (security review finding — this
        // was missing, so a provider debug flag like MYCOOLPAY_DEBUG
        // would log it in cleartext). Any `X-*-KEY` header naming
        // convention is covered by the same entry, case-insensitively.
        'x-private-key',
        'x-hub-signature',
        'x-hub-signature-256',
        'cookie',
        'set-cookie',
    ];

    /**
     * @var list<string>
     *
     * Substrings matched case-insensitively against JSON-key paths. The
     * list is intentionally **closed** (not a regex) so additions are
     * reviewable. The set covers the camelCase variants seen across the
     * ecosystem (`confirmationPassword`, `confirmationPin`,
     * `apiSecret`, …) and the obvious short forms (`pwd`, `pin`, `pwd`).
     */
    public const KEY_SUBSTRINGS = [
        'secret',
        'password',
        'passwd',
        'pwd',
        'pin',
        'token',
        'apikey',
        'api_key',
        'apiSecret',
        'client_secret',
        'private_key',
        'authorization',
        'authentication',
        'authentication_token',
        'credential',
        'confirmation',
        'salt',
        // Audit 2026-10-29 #2 (ADR-0081 S-17) — the one-time codes (TOTP,
        // SMS, verification): a code replayed inside its validity window
        // is a second factor in the clear. Targeted names, not the bare
        // `code` substring: that one would also blank `status_code`,
        // `provider_code`, `error_code` or `postal_code`, the very signal
        // an operator needs in a log line. The bare `code` / `otp` field
        // names are matched exactly instead (see EXACT_KEYS).
        'totp',
        'otp_code',
        'verification_code',
        'mfa_code',
        'sms_code',
        // Audit 2026-10-29 #3 — the MAC / signature of a provider callback
        // (MyCoolPay: md5 of fields + private key) is a credential that
        // replays a forged callback; `signature` was only a header name.
        'signature',
        'hmac',
        'checksum',
        // ADR-0083 §1 / ADR-0081 S-17 — the **personal data**: a name,
        // an address, a phone number. They are encrypted at rest
        // (ADR-0083 §2), and they must never be written in clear in a
        // log either. `phone` covers `phone_number` and `telephone`;
        // `msisdn` is the mobile number the West African providers
        // name it.
        'email',
        'phone',
        'msisdn',
        'display_name',
        'full_name',
        'first_name',
        'last_name',
    ];

    /**
     * @var list<string>
     *
     * Key names matched case-insensitively **in full**, never as a
     * substring: too short to be safe as a substring (`code` would match
     * `status_code`, `postal_code`; `mac` would match `machine`; `otp`
     * would match `footprint`) yet exactly the name the console and the
     * providers give to a secret (the console's MFA field is `code`;
     * MyCoolPay's callback MAC may be named `mac`).
     */
    public const EXACT_KEYS = [
        'code',
        'otp',
        'mac',
    ];

    /**
     * @param list<string> $extraHeaderNames additional header names to redact
     * @param list<string> $extraKeySubstrings additional key substrings to redact
     */
    public function __construct(
        private readonly array $extraHeaderNames = [],
        private readonly array $extraKeySubstrings = [],
    ) {}

    /**
     * @return list<string>
     */
    public function headerNames(): array
    {
        return array_merge(self::HEADER_NAMES, $this->extraHeaderNames);
    }

    /**
     * @return list<string>
     */
    public function keySubstrings(): array
    {
        return array_merge(self::KEY_SUBSTRINGS, $this->extraKeySubstrings);
    }

    /**
     * @return list<string>
     */
    public function exactKeys(): array
    {
        return self::EXACT_KEYS;
    }
}
