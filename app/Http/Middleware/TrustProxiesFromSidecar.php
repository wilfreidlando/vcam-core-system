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
 * before anything else (logs, controllers) can see it.
 *
 * Audit 2026-10-29 #8 — an empty token used to fall back to trusting every
 * private address, which is precisely the spoofing door above: outside a
 * development environment the token is now mandatory, and an
 * `APP_ENV=production` application with a missing, empty or too short token
 * refuses to serve (`MissingTrustedProxyToken`, like `PersonalDataKeyring`
 * does for `PII_ENCRYPTION_KEYS`). Local, testing and other environments keep
 * the previous behaviour.
 */
final class TrustProxiesFromSidecar extends TrustProxies
{
    public const HEADER = 'X-Core-Proxy-Token';

    /** Shortest accepted token: `openssl rand -hex 32` gives 64 characters. */
    public const MINIMUM_TOKEN_LENGTH = 32;

    /**
     * @throws MissingTrustedProxyToken when the environment is production and the token is unusable
     */
    public static function assertConfigured(string $environment, mixed $token): void
    {
        if ($environment !== 'production') {
            return;
        }

        if (! is_string($token) || $token === '') {
            throw MissingTrustedProxyToken::empty();
        }

        if (strlen($token) < self::MINIMUM_TOKEN_LENGTH) {
            throw MissingTrustedProxyToken::tooShort(self::MINIMUM_TOKEN_LENGTH);
        }
    }

    /**
     * @return mixed same as the parent middleware
     */
    public function handle(Request $request, Closure $next)
    {
        $expected = config('trustedproxy.sidecar_token');
        self::assertConfigured(app()->environment(), $expected);
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
