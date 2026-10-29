<?php

declare(strict_types=1);

namespace Core\Portal\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0075 §Sécurité — the headers of every page of the portal.
 *
 * The policy is deliberately tight: nothing but our own stylesheet, no
 * script at all, images from our origin or https (the project's logo),
 * forms posting only to us, never framed. A page served here cannot be
 * turned into a phishing overlay or leak a link through the referrer.
 */
final class PortalSecurityHeaders
{
    public const CSP = "default-src 'none'; style-src 'self'; img-src 'self' https: data:; "
        ."form-action 'self'; base-uri 'none'; frame-ancestors 'none'";

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
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
