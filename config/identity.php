<?php

declare(strict_types=1);

/**
 * `disable_totp_in_local` is a local-only developer convenience: it
 * keeps the MFA enrollment/verify screens but makes the TOTP code itself
 * accept any 6-digit value. See `IdentityServiceProvider::register()`,
 * which checks the environment in code, not just this flag, before
 * binding the permissive provider — no env var alone enables it outside
 * `local`.
 *
 * `disable_mfa` removes the MFA step entirely from the login flow: a
 * successful email+password check immediately issues a Sanctum session
 * token. Audit 2026-10-29 #12: like `disable_totp_in_local`, it is gated
 * in code — `IdentityServiceProvider::register()` throws `MfaBypassForbidden`
 * when it is true outside `APP_ENV=local`, so no environment variable alone
 * can switch MFA off elsewhere. Default is `false`. The auth logs still record
 * `operator.mfa_skipped` on every login when this is on, so an audit
 * review of who signed in during a disabled-MFA window remains
 * possible.
 */
return [
    'disable_totp_in_local' => (bool) env('IDENTITY_DISABLE_TOTP_IN_LOCAL', false),
    'disable_mfa' => (bool) env('IDENTITY_DISABLE_MFA', false),

    // ADR-0082 §1 — API keys a project may hold at once. Every key that still
    // authenticates counts; a rotation may go one over while the old key
    // finishes its grace period.
    'api_keys' => [
        'max_per_project' => (int) env('IDENTITY_API_KEYS_MAX_PER_PROJECT', 10),
    ],
];
