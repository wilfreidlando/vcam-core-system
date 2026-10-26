<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

/**
 * The two planes the operator console answers on.
 *
 * The console is not one HTTP surface: the same capability exists twice —
 * once as JSON under `/v1/admin/*` (bearer token, `operator.auth`) and
 * once as HTML under `/admin/*` (server-side session, `operator.session`).
 * A guard that only covered one of them would leave the other unguarded,
 * so the manifest records the plane of every route it lists.
 */
enum ConsolePlane: string
{
    /** `/admin/*` — session + cookie, HTML, CSRF-protected. */
    case Web = 'web';

    /** `/v1/admin/*` — bearer token, JSON, RFC 7807 problem details. */
    case Json = 'json';
}
