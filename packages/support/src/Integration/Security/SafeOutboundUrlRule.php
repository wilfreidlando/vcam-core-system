<?php

declare(strict_types=1);

namespace Core\Support\Integration\Security;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Laravel `FormRequest` rule wrapping `OutboundUrlGuard` — shared by
 * every place a Project supplies a destination URL of its own choosing:
 * `POST /v1/webhook-endpoints` and per-payment `callback_url`. Kept as a
 * single rule so both stay in sync rather than drifting into two
 * independent (and possibly inconsistent) SSRF checks.
 *
 * HTTPS is required (audit 2026-10-03 §2.3 — payment statuses and amounts
 * must never travel in clear text) unless `integration.outbound.allow_insecure_http`
 * is explicitly enabled for local development.
 *
 * The private ranges (RFC 1918, unique-local IPv6) are refused by default:
 * a Project's endpoint is a public address (ADR-0115). In local
 * development they are acceptable — the Core and the client application it
 * talks to share a host or a Docker network, and the operator registers
 * the application there.
 */
final class SafeOutboundUrlRule implements ValidationRule
{
    public function __construct(
        private readonly OutboundUrlGuard $guard = new OutboundUrlGuard,
        private readonly ?bool $allowInsecureHttp = null,
        private readonly ?bool $allowPrivateTargets = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->guard()->isSafe($value)) {
            $fail('The :attribute must be a publicly reachable http(s) URL.');

            return;
        }

        if (! $this->insecureHttpAllowed() && strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https') {
            $fail('The :attribute must use https.');
        }
    }

    /**
     * The guard to check the URL with.
     *
     * The guard is immutable: the local-development behaviour is a
     * **copy** of it, never a mutation of the shared one.
     */
    private function guard(): OutboundUrlGuard
    {
        if (! $this->privateTargetsAllowed()) {
            return $this->guard;
        }

        return $this->guard->allowsPrivateTargets()
            ? $this->guard
            : $this->guard->withPrivateTargets();
    }

    private function privateTargetsAllowed(): bool
    {
        if ($this->allowPrivateTargets !== null) {
            return $this->allowPrivateTargets;
        }

        return (bool) config('integration.outbound.allow_private_targets', false);
    }

    private function insecureHttpAllowed(): bool
    {
        if ($this->allowInsecureHttp !== null) {
            return $this->allowInsecureHttp;
        }

        return (bool) config('integration.outbound.allow_insecure_http', false);
    }
}
