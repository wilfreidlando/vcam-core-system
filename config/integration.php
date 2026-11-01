<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Outbound calls to caller-supplied URLs (webhook endpoints, callback_url)
|--------------------------------------------------------------------------
|
| Audit 2026-10-03 §2.1/§2.3. These settings govern only URLs a Project
| chooses (never a catalogued provider's base URL).
|
*/

return [
    'outbound' => [
        // `http://` is refused unless explicitly allowed — local
        // development against a plain-HTTP receiver only. Never in production.
        'allow_insecure_http' => (bool) env('OUTBOUND_ALLOW_INSECURE_HTTP', false),

        // ADR-0115 — the private ranges (RFC 1918, unique-local IPv6) are
        // refused by default: a Project's endpoint is a public address.
        // A local deployment needs them — the Core and the client
        // application it talks to share a host or a Docker network — so the
        // flag opens them **in local only**: `IntegrationServiceProvider`
        // refuses to start outside `APP_ENV=local`, whatever this value
        // says. The loopback and the link-local range (the cloud metadata
        // address lives there) stay refused even in local.
        'allow_private_targets' => (bool) env('OUTBOUND_ALLOW_PRIVATE_TARGETS', false),

        // Re-resolve and re-check the host at connection time, then pin
        // the connection to the checked address (CURLOPT_RESOLVE) — closes
        // DNS rebinding between registration and delivery.
        'enforce_at_connect' => (bool) env('OUTBOUND_ENFORCE_AT_CONNECT', true),
    ],
];
