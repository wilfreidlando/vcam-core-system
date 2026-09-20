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
 * domain that resolves to an internal address. This is a static,
 * validation-time check, not a connection-time guarantee: a genuinely
 * adversarial DNS-rebinding attack (the host resolves safely now, then
 * to an internal address at the moment the HTTP client actually
 * connects) is not defended against here. Closing that fully would
 * require pinning the resolved IP through to the HTTP client itself,
 * which neither `packages/webhook`'s delivery path nor
 * `LaravelHttpTransport` do today — documented residual risk, not
 * silently assumed away.
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
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), self::ALLOWED_SCHEMES, true)) {
            return false;
        }

        $host = strtolower($parts['host']);
        if (in_array($host, self::BLOCKED_HOSTNAMES, true) || str_ends_with($host, '.local')) {
            return false;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : ($this->resolveHost)($host);

        if ($addresses === []) {
            // Unresolvable — refuse rather than let an edge case slip through.
            return false;
        }

        foreach ($addresses as $address) {
            if (! $this->isPubliclyRoutable($address)) {
                return false;
            }
        }

        return true;
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

    private function isPubliclyRoutable(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
