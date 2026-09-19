<?php

declare(strict_types=1);

/*
 * Published (not left at Sanctum's stock defaults) for two reasons
 * (ADR-0044, amended by the ADR-0047 web-console auth fix):
 *
 * - The stock default `'expiration' => null` means an operator's access
 *   token never expires. Combined with mandatory MFA enforced only at
 *   login, that reduces MFA to a one-time gate rather than an ongoing
 *   control — a leaked/stolen token stays valid forever. Bounded here
 *   via `OPERATOR_TOKEN_TTL_MINUTES` instead.
 * - `'routes' => false` disables Sanctum's own `/sanctum/csrf-cookie`
 *   route. An earlier attempt at the admin web console tried Sanctum's
 *   "stateful" cookie mode for browser sessions, which cannot work here
 *   even in principle: Sanctum never re-exposes a token's plaintext
 *   after issuance, so a later request can't recover a bearer token
 *   from a resolved cookie session. The web console instead stores the
 *   plaintext bearer token in Laravel's own server-side session
 *   (`AuthController`/`ResolveOperatorSession`) and resolves it through
 *   the identical `ResolveOperatorFromBearerToken` use case the API
 *   bearer plane uses — no Sanctum stateful machinery, no cookie mode,
 *   for either plane.
 */
return [

    'guard' => ['web'],

    'expiration' => (int) env('OPERATOR_TOKEN_TTL_MINUTES', 480),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'routes' => false,

];
