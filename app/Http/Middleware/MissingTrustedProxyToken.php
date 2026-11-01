<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use RuntimeException;

/**
 * Audit 2026-10-29 #8 — `TRUSTED_PROXY_TOKEN` is missing, empty or too short
 * in production. Without it every private address would be trusted as a
 * proxy and any neighbouring container could forge the client IP (MyCoolPay
 * allowlist, per-IP rate limits).
 */
final class MissingTrustedProxyToken extends RuntimeException
{
    public static function empty(): self
    {
        return new self('TRUSTED_PROXY_TOKEN est absent ou vide alors que APP_ENV=production : générez-le avec `openssl rand -hex 32` (le sidecar nginx le pose dans l\'en-tête X-Core-Proxy-Token).');
    }

    public static function tooShort(int $minimum): self
    {
        return new self(sprintf('TRUSTED_PROXY_TOKEN est trop court (%d caractères minimum) : générez-le avec `openssl rand -hex 32`.', $minimum));
    }
}
