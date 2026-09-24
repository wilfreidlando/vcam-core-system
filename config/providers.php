<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Provider configuration
    |--------------------------------------------------------------------------
    |
    | Each entry declares ONE outbound provider integration. The shape is
    | consumed by `Core\Support\Integration\Configuration\ProviderConfig::fromArray()`
    | (added at Lot 3 alongside the ProviderConfigLoader). Until the loader
    | is in place, adapters are constructed explicitly in tests and in the
    | application shell — config() and config/providers.php are kept in sync
    | by hand.
    |
    | Required keys per entry:
    |   code              string  provider identifier (e.g. 'my-coolpay')
    |   base_url          string  provider API root
    |   auth              array   AuthStrategy::fromArray() input
    |   credentials       array   Credentials::fromArray() input (defaults)
    |   timeouts          array   TimeoutPolicy::fromArray() input
    |   retries           array   RetryPolicy::fromArray() input
    |   breaker           array   CircuitBreakerPolicy::fromArray() input
    |   enabled           bool    kill switch (default true)
    |   debug             bool    verbose logging flag (default false)
    |   extras            array   provider-specific key-value pairs
    |
    | Optional keys:
    |   signature         array   SignaturePolicy::fromArray() input (HMAC etc.)
    |
    */

    'environment' => env('PROVIDER_ENV', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | My-CoolPay (DHI) — Cameroonian payment aggregator
    |--------------------------------------------------------------------------
    |
    | Public key is in the URL path. Private key is sent as X-PRIVATE-KEY
    | header on the sensitive operations (payout, balance) — those are
    | NOT implemented in this first slice. The four operations that
    | don't need the private key (payin, paylink, payin/authorize,
    | checkStatus) set `requiresAuth: false` on the request and go
    | through with the public-key-in-path only.
    |
    */

    'payment' => [
        'my_coolpay' => [
            'code' => 'my-coolpay',
            'adapter' => Core\Payment\Infrastructure\Provider\MyCoolPay\MyCoolPayAdapter::class,
            'environment' => env('MYCOOLPAY_ENV', 'sandbox'),
            'base_url' => env('MYCOOLPAY_BASE_URL', 'https://my-coolpay.com'),
            'enabled' => env('MYCOOLPAY_ENABLED', true),
            'debug' => env('MYCOOLPAY_DEBUG', false),

            'auth' => [
                // MyCoolPay's four MVP operations (payin, paylink,
                // payin/authorize, checkStatus) don't require the private
                // key. We declare api-key anyway so the kind is concrete
                // and the foundation's AuthProvider iteration works
                // uniformly — the adapter simply sets requiresAuth=false
                // per request.
                'kind' => 'api-key',
                'api_key_header' => 'X-PRIVATE-KEY',
            ],

            'credentials' => [
                'api_key' => env('MYCOOLPAY_PRIVATE_KEY'),
            ],

            // MyCoolPay's payin answers only once the operator has been
            // queried, which measured ~17s against the real API — the
            // previous 15s made the first attempt time out while the
            // transaction was in fact created, and the automatic retry then
            // got `409 Duplicate transaction reference`, surfacing a 502 for
            // a payment that existed. Two attempts at 30s keep the worst
            // case (~62s) inside the FrankenPHP/nginx 90s ceilings.
            'timeouts' => [
                'connect' => 2.0,
                'request' => 30.0,
                'total' => 62.0,
            ],

            'retries' => [
                'max_attempts' => 2,
                'base_delay_ms' => 500,
                'max_delay_ms' => 5000,
                'retryable_statuses' => [502, 503, 504, 429, 408],
            ],

            'breaker' => [
                'failure_threshold' => 5,
                'open_duration_seconds' => 60,
            ],

            'extras' => [
                'public_key' => env('MYCOOLPAY_PUBLIC_KEY'),
                // Callback ("webhook") authenticity, per MyCoolPay's own
                // docs §7.5 "Callback requests integrity" — the single IP
                // their servers send callbacks from, at time of writing.
                // Comma-separated for a future multi-IP allowlist; empty
                // disables the IP check (signature check stays mandatory
                // regardless — see MyCoolPayCallbackAuthenticator).
                'callback_trusted_ips' => array_values(array_filter(
                    array_map('trim', explode(',', (string) env('MYCOOLPAY_CALLBACK_TRUSTED_IPS', '15.236.140.89'))),
                    static fn (string $ip): bool => $ip !== '',
                )),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WAHA (self-hosted WhatsApp HTTP API) — ADR-0042
    |--------------------------------------------------------------------------
    |
    | `base_url` points at the operator's own WAHA instance, not a
    | third-party SaaS. Auth is a single API key in the `X-Api-Key`
    | header, applied to every call (no per-operation requiresAuth:false
    | exceptions, unlike MyCoolPay). There is no default session: every
    | send names the caller's own linked session in `variables['session']`.
    | `extras.webhook_secret` is compared against the
    | `X-Webhook-Secret` header WAHA is configured to send on its
    | outbound webhook calls (ADR-0042 §8) — set it to the same value on
    | both sides.
    |
    */

    'notification' => [
        'whatsapp_waha' => [
            'code' => 'whatsapp-waha',
            'base_url' => env('WAHA_BASE_URL', 'http://localhost:3000'),
            'enabled' => env('WAHA_ENABLED', true),
            'debug' => env('WAHA_DEBUG', false),

            'auth' => [
                'kind' => 'api-key',
                'api_key_header' => 'X-Api-Key',
            ],

            'credentials' => [
                'api_key' => env('WAHA_API_KEY'),
            ],

            'timeouts' => [
                'connect' => 2.0,
                'request' => 15.0,
                'total' => 30.0,
            ],

            'retries' => [
                'max_attempts' => 3,
                'base_delay_ms' => 500,
                'max_delay_ms' => 5000,
                'retryable_statuses' => [502, 503, 504, 429, 408],
            ],

            'breaker' => [
                'failure_threshold' => 5,
                'open_duration_seconds' => 60,
            ],

            'extras' => [
                'webhook_secret' => env('WAHA_WEBHOOK_SECRET'),
            ],
        ],
    ],
];
