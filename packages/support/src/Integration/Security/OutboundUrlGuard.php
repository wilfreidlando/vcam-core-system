<?php

declare(strict_types=1);

namespace Core\Support\Integration\Security;

/**
 * Rejects a caller-supplied URL the Core would make outbound requests to
 * if its host resolves to a private, loopback, link-local (includes the
 * `169.254.169.254` cloud metadata address), or otherwise reserved IP
 * range — SSRF protection for every place a Project supplies a
 * destination URL of its own choosing: registered webhook endpoints
 * (`packages/webhook`) and per-payment callback URLs (`packages/payment`).
 * Security review finding: neither had any validation at all.
 *
 * Pure PHP, no framework dependency (G3b) — lives in the shared kernel
 * because both consumers are separate modules (G3d: this class holds no
 * state and persists nothing, so it's within `core/support`'s bounds).
 *
 * Resolves the hostname and checks every returned address, not just the
 * literal host string — catches the common case of a caller-controlled
 * domain that resolves to an internal address. Used twice: at validation
 * time (`SafeOutboundUrlRule`) and again at connection time by
 * `GuardedHttpTransport`, which pins the connection to the address checked
 * here — closing the DNS-rebinding window (ADR-0058).
 *
 * DNS resolution is injected (defaults to the real system resolver) so
 * tests stay hermetic — no live network/DNS access required to exercise
 * the blocking logic itself.
 */
final class OutboundUrlGuard
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * @var list<string>
     */
    private const BLOCKED_HOSTNAMES = ['localhost'];

    /**
     * Not covered by PHP's private/reserved flags.
     *
     * @var list<string>
     */
    private const EXTRA_BLOCKED_IPV4_CIDRS = [
        '100.64.0.0/10', // CGNAT (RFC 6598) — e.g. 100.100.100.200 metadata
        '198.18.0.0/15', // benchmarking (RFC 2544)
        '192.0.0.0/24',  // IETF protocol assignments
    ];

    /**
     * @var callable(string): list<string>
     */
    private $resolveHost;

    /**
     * @param (callable(string): list<string>)|null $resolveHost Returns every
     *                                                           IP address a hostname resolves to, or an empty list if
     *                                                           it doesn't resolve. Defaults to real DNS
     *                                                           (`dns_get_record`).
     */
    public function __construct(?callable $resolveHost = null)
    {
        $this->resolveHost = $resolveHost ?? self::systemResolver(...);
    }

    public function isSafe(string $url): bool
    {
        return $this->resolvePublicAddresses($url) !== [];
    }

    /**
     * Every address `$url`'s host resolves to, or an empty list when the
     * URL is not safe to call (bad scheme, blocked host, unresolvable, or
     * ANY resolved address not publicly routable). The non-empty result
     * is what `GuardedHttpTransport` pins the connection to, so the
     * address checked here is the address actually connected to — closing
     * the DNS-rebinding window between validation and connection.
     *
     * @return list<string>
     */
    public function resolvePublicAddresses(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return [];
        }

        if (! in_array(strtolower($parts['scheme']), self::ALLOWED_SCHEMES, true)) {
            return [];
        }

        $host = strtolower($parts['host']);
        if (in_array($host, self::BLOCKED_HOSTNAMES, true) || str_ends_with($host, '.local')) {
            return [];
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : ($this->resolveHost)($host);

        if ($addresses === []) {
            // Unresolvable — refuse rather than let an edge case slip through.
            return [];
        }

        foreach ($addresses as $address) {
            if (! $this->isPubliclyRoutable($address)) {
                return [];
            }
        }

        return $addresses;
    }

    /**
     * @return list<string>
     */
    private static function systemResolver(string $host): array
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                $addresses[] = $record['ip'];
            }
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    /**
     * `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` alone lets
     * through (verified on PHP 8.3, audit 2026-10-03 §2.4): IPv4-mapped
     * IPv6 (`::ffff:127.0.0.1`), CGNAT `100.64.0.0/10` (some clouds'
     * metadata endpoints live there), NAT64 `64:ff9b::/96` and the
     * benchmarking range `198.18.0.0/15`. Mapped/NAT64 forms are unwrapped
     * to their embedded IPv4 and re-checked; the extra ranges are blocked
     * explicitly.
     */
    private function isPubliclyRoutable(string $ip): bool
    {
        $embedded = self::embeddedIpv4($ip);
        if ($embedded !== null) {
            return $this->isPubliclyRoutable($embedded);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::EXTRA_BLOCKED_IPV4_CIDRS as $cidr) {
            if (self::ipv4InCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function embeddedIpv4(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        $prefix = substr($packed, 0, 12);
        $isMapped = $prefix === str_repeat("\0", 10)."\xff\xff";
        $isNat64 = $prefix === "\x00\x64\xff\x9b".str_repeat("\0", 8);
        $isCompatible = $prefix === str_repeat("\0", 12) && substr($packed, 12) !== "\0\0\0\0" && substr($packed, 12) !== "\0\0\0\1";

        if (! $isMapped && ! $isNat64 && ! $isCompatible) {
            return null;
        }

        $v4 = inet_ntop(substr($packed, 12));

        return $v4 === false ? null : $v4;
    }

    private static function ipv4InCidr(string $ip, string $cidr): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        [$network, $bits] = explode('/', $cidr);
        $mask = -1 << (32 - (int) $bits);

        return (ip2long($ip) & $mask) === (ip2long($network) & $mask);
    }
}
