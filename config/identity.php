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
 * token. Unlike `disable_totp_in_local` this flag is NOT env-gated —
 * the operator runtime applies it in every environment where it's set.
 * Default is `false`; setting it to `true` is a deliberate choice for a
 * development / non-production runtime. The auth logs still record
 * `operator.mfa_skipped` on every login when this is on, so an audit
 * review of who signed in during a disabled-MFA window remains
 * possible.
 */
return [
    'disable_totp_in_local' => (bool) env('IDENTITY_DISABLE_TOTP_IN_LOCAL', false),
    'disable_mfa' => (bool) env('IDENTITY_DISABLE_MFA', false),
];
