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
 */
final class SafeOutboundUrlRule implements ValidationRule
{
    public function __construct(
        private readonly OutboundUrlGuard $guard = new OutboundUrlGuard,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->guard->isSafe($value)) {
            $fail('The :attribute must be a publicly reachable http(s) URL.');
        }
    }
}
