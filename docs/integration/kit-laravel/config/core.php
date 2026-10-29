<?php

declare(strict_types=1);

return [
    // Base URL of the Core (staging and production are two deployments).
    'base_url' => env('CORE_BASE_URL', 'https://core.example.com'),

    // The project's API key (`pk_…`), template « Serveur complet ».
    'api_key' => env('CORE_API_KEY'),

    // The secret (`whsec_…`) returned ONCE when you register your webhook endpoint.
    'webhook_secret' => env('CORE_WEBHOOK_SECRET'),

    // The plan a brand-new tenant starts on, and for how long it is free.
    'default_plan' => env('CORE_DEFAULT_PLAN', 'pro'),
    'trial_days' => (int) env('CORE_TRIAL_DAYS', 14),

    // Where the portal's « Retour » button sends the customer. It must also
    // be listed in `allowed_return_urls` (PUT /v1/portal/settings).
    'return_url' => env('CORE_RETURN_URL', env('APP_URL').'/billing'),

    // How long a rights check may be served from cache (ADR-0084 §3: 60 s).
    'entitlements_ttl' => 60,
];
