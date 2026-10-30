<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0081 S-11 — the security headers of every page of the operator console.
 *
 * The customer portal has always had its own (`PortalSecurityHeaders`); the
 * console, which moves the money, had none: it could be framed by any site
 * (a hidden iframe with a button laid over « Valider et écrire au grand livre »),
 * leaked `/admin/projects/{id}/customers/{ref}` to every third party through the
 * `Referer`, and let the browser and any proxy cache the page that shows an API
 * key in clear.
 *
 * The CSP: who may frame us, where a form may post, which `<base>` and which
 * plugins are allowed — and, since Tailwind and Alpine are served from
 * `public/assets/admin/vendor/` (F-05), which hosts may run a script: ours and
 * no other. A compromised CDN can no longer execute code on the console.
 *
 * `'unsafe-inline'` is GONE from `script-src` (ADR-0102): the layout's inline
 * script — the mobile-nav store — is now a file served by the application
 * (`public/assets/admin/console.js`), and no view carries an inline `on*=`
 * handler any more. A script can therefore only come from our own origin.
 *
 * `'unsafe-eval'` stays, deliberately and for now: the standard Alpine build
 * evaluates the expressions of the templates with `new Function`. Removing it
 * means Alpine's CSP build (`@alpinejs/csp`), which evaluates them by token
 * substitution instead — a rewrite of the ~60 expressions of the console.
 * That lot is described in ADR-0102, décision 3; its integration is subject to
 * the approval of the external source it comes from.
 */
final class AdminSecurityHeaders
{
    public const CSP = "base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; "
        ."script-src 'self' 'unsafe-eval'";

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', self::CSP);
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        // A page that may show a secret (an API key, a webhook secret, an invitation token)
        // must never sit in a browser or proxy cache.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
