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
}
