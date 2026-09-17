<?php

declare(strict_types=1);

/*
 * Published (not left at Sanctum's stock defaults) for two reasons
 * (ADR-0044, follow-up fix):
 *
 * - The stock default `'expiration' => null` means an operator's access
 *   token never expires. Combined with mandatory MFA enforced only at
 *   login, that reduces MFA to a one-time gate rather than an ongoing
 *   control — a leaked/stolen token stays valid forever. Bounded here via
 *   `OPERATOR_TOKEN_TTL_MINUTES` instead.
 *
 * Mixed mode:
 * - `routes` is TRUE so the `/sanctum/csrf-cookie` endpoint is
 *   available to the admin web UI (which needs CSRF protection on
 *   form POSTs). The API itself never reads cookies — it stays bearer
 *   auth only — so enabling the route has no impact on the API plane.
 * - `stateful` includes only the `web` middleware group, which is the
 *   group the admin web UI lives in. The `api` group stays
 *   bearer-token-only — Sanctum never reads cookies there.
 * - `expiration` is still the operator's token TTL (same value used by
 *   both bearer and cookie modes).
 */
return [

    'guard' => ['web'],

    'expiration' => (int) env('OPERATOR_TOKEN_TTL_MINUTES', 480),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'stateful' => explode(',', (string) env('SANCTUM_STATEFUL_GUARDS', 'web')),

    'routes' => true,

];
