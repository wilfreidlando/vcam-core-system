<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Trusted reverse proxies — read by Laravel's global TrustProxies middleware
|--------------------------------------------------------------------------
|
| Deployed topology (ADR-0053): the app container is reached only through
| two reverse proxies (the shared nginx-proxy, then this project's nginx
| sidecar), both on the Docker-internal network.
|
| Audit 2026-10-03 §2.2: the previous `trustProxies(at: '*')` trusts ONLY
| the immediate caller (the sidecar), so with two hops `$request->ip()` was
| the nginx-proxy container's address, never the real client. That broke
| the MyCoolPay source-IP allowlist and collapsed every per-IP throttle
| into one global bucket. Trusting the private ranges the proxies live in
| lets Symfony walk X-Forwarded-For right-to-left past every proxy hop to
| the first public address — the real client. A spoofed left-most entry is
| never reached, because the client's own public address sits to its right.
|
*/

return [
    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7'),

    // ADR-0064 — when set, forwarded headers are trusted only on requests
    // carrying this token, added by this project's nginx sidecar
    // (App\Http\Middleware\TrustProxiesFromSidecar). Required once the
    // app shares the VPS-wide `observability` network with other projects.
    'sidecar_token' => env('TRUSTED_PROXY_TOKEN', ''),
];
