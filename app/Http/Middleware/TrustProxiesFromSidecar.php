<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * ADR-0064 — the app container also sits on the VPS-wide `observability`
 * network (metrics scraping, traces), shared with other projects. Trusting
 * every private address as a proxy (config/trustedproxy.php) would then let
 * any container on that network call the app directly with a forged
 * `X-Forwarded-For` — e.g. pretending to be MyCoolPay's IP.
 *
 * When `trustedproxy.sidecar_token` is set, forwarded headers are honoured
 * only on requests carrying that token, which this project's own nginx
 * sidecar adds (docker/nginx/prod/app.conf.template). Any other caller is
 * treated as the client itself. The token is removed from the request
 * before anything else (logs, controllers) can see it. Empty token: the
 * previous behaviour, unchanged.
 */
final class TrustProxiesFromSidecar extends TrustProxies
{
    public const HEADER = 'X-Core-Proxy-Token';

    /**
     * @return mixed same as the parent middleware
     */
    public function handle(Request $request, Closure $next)
    {
        $expected = config('trustedproxy.sidecar_token');
        $given = $request->headers->get(self::HEADER);
        $request->headers->remove(self::HEADER);

        if (is_string($expected) && $expected !== ''
            && ! (is_string($given) && hash_equals($expected, $given))) {
            $headers = $this->getTrustedHeaderNames();
            assert($headers >= 0 && $headers <= 63);
            $request::setTrustedProxies([], $headers);

            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
