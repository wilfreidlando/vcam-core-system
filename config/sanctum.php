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
 * - `'routes' => false` disables Sanctum's own `/sanctum/csrf-cookie`
 *   route: this platform is API-only for operators (bearer tokens via
 *   `Authorization` header), never the SPA/cookie session mode that
 *   route exists for (ADR-0044 §3 — "jetons API purs, pas de mode
 *   SPA/cookie").
 */
return [

    'guard' => ['web'],

    'expiration' => (int) env('OPERATOR_TOKEN_TTL_MINUTES', 480),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'routes' => false,

];
