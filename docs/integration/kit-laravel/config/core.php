<?php

declare(strict_types=1);

return [
    // Base URL of the Core (staging and production are two deployments).
    'base_url' => env('CORE_BASE_URL', 'https://core.example.com'),

    // The project's API key (`pk_…`). Give it only the scopes you use
    // (templates: « Serveur complet », « Paiements », « Portail »…).
    'api_key' => env('CORE_API_KEY'),

    // Seconds. A payment start waits for the provider: keep `timeout` generous.
    'timeout' => (int) env('CORE_TIMEOUT', 30),
    'connect_timeout' => (int) env('CORE_CONNECT_TIMEOUT', 5),

    // The secret (`whsec_…`) returned ONCE when you register your webhook endpoint.
    // A payment created with a `callback_url` returns its own `pcbsec_…`: pass it
    // to CoreWebhookController::verify() yourself if you use callbacks.
    'webhook_secret' => env('CORE_WEBHOOK_SECRET'),

    // WhatsApp: the channel-session your Core administrator linked for this project.
    'notifications' => [
        'whatsapp_session' => env('CORE_WHATSAPP_SESSION'),
    ],

    // Only needed if you sell subscriptions through the Core (SaaS commerce).
    'billing' => [
        // The plan a brand-new tenant starts on, and for how long it is free.
        'default_plan' => env('CORE_DEFAULT_PLAN', 'pro'),
        'trial_days' => (int) env('CORE_TRIAL_DAYS', 14),

        // Where the portal's « Retour » button sends the customer. It must also
        // be listed in `allowed_return_urls` (PUT /v1/portal/settings).
        'return_url' => env('CORE_RETURN_URL', env('APP_URL').'/billing'),

        // How long a rights check may be served from cache (ADR-0084 §3: 60 s).
        'entitlements_ttl' => 60,
    ],
];
