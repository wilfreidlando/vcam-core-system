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
 */
final class SafeOutboundUrlRule implements ValidationRule
{
    public function __construct(
        private readonly OutboundUrlGuard $guard = new OutboundUrlGuard,
        private readonly ?bool $allowInsecureHttp = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->guard->isSafe($value)) {
            $fail('The :attribute must be a publicly reachable http(s) URL.');

            return;
        }

        if (! $this->insecureHttpAllowed() && strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https') {
            $fail('The :attribute must use https.');
        }
    }

    private function insecureHttpAllowed(): bool
    {
        if ($this->allowInsecureHttp !== null) {
            return $this->allowInsecureHttp;
        }

        return (bool) config('integration.outbound.allow_insecure_http', false);
    }
}
