<?php

declare(strict_types=1);

/*
| ADR-0085 §1 — rate limits once the caller is known to be a project.
|
| Budgets are per minute (a fixed window; the burst allowance of the ADR is the
| window itself). The API-key budget defaults to half of the project's. An
| increase for one project is a platform setting: put it under `projects`,
| keyed by project id — and record the change in the audit log when you do.
|
| `classes` budget the costly routes per project: the key is the permission the
| route needs (`payments:write`, `notifications:write`).
*/
return [
    'enabled' => (bool) env('RATE_LIMIT_ENABLED', true),

    'project_per_minute' => (int) env('RATE_LIMIT_PROJECT_PER_MINUTE', 3000),

    'key_per_minute' => (int) env('RATE_LIMIT_KEY_PER_MINUTE', 1500),

    'classes' => [
        'payments:write' => 600,
        'notifications:write' => 600,
    ],

    /** @var array<string, array{project_per_minute?: int, key_per_minute?: int}> */
    'projects' => [],
];
