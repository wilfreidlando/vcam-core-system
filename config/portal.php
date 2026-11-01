<?php

declare(strict_types=1);

/*
| ADR-0075 §2, ADR-0100 — the budgets of the login by code (ADR-0085 §1).
|
| Hourly, counted in the cache; when the cache cannot be read the action is
| refused (fail closed). The project cap protects the messages a project
| pays for: at the cap, codes simply stop leaving until the next hour.
*/
return [
    'direct_login' => [
        'codes_per_phone_per_hour' => (int) env('PORTAL_LOGIN_CODES_PER_PHONE_PER_HOUR', 5),
        'codes_per_ip_per_hour' => (int) env('PORTAL_LOGIN_CODES_PER_IP_PER_HOUR', 30),
        'verifications_per_ip_per_hour' => (int) env('PORTAL_LOGIN_VERIFICATIONS_PER_IP_PER_HOUR', 60),
        'project_hourly_cap' => (int) env('PORTAL_LOGIN_PROJECT_HOURLY_CAP', 200),
    ],
];
