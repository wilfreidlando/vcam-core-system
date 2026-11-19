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
 * token. Audit 2026-10-29 #12.
 *
 * ADR-0128 — **one variable, everywhere**: when it is true, the second
 * factor is off on every runtime — the developer's machine, the staging
 * deployment (ADR-0064) and the live one. There is no second variable, no
 * environment to declare, no condition to satisfy: the flag is the decision.
 *
 * What replaces the code-level refusal that ADR-0128 removed is the
 * **trace**: every login without MFA is recorded in the audit log as
 * `operator.mfa_skipped` (both planes), and the console says so when it
 * hands the session out. A window without MFA is therefore visible in the
 * journal, whoever armed the flag.
 *
 * Default is `false`.
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
