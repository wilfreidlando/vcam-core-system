<?php

declare(strict_types=1);

return [
    // ADR-0062 / decision D5: a notification's content and variables
    // (recipient phone number…) are visible to authorized operators for
    // this many days, then emptied by `notification:redact-expired`.
    'content_retention_days' => (int) env('NOTIFICATION_CONTENT_RETENTION_DAYS', 90),

    // ADR-0118 §2 — the session the **Core's own** messages leave from:
    // the global one, registered by the operator for the platform. Its
    // default value stays `default` — and it is the only use of that name:
    // the client's own path resolves the session the caller names, with no
    // fallback to `default` (ADR-0114 §3).
    //
    // The session is resolved by exact ownership (a global one), so a
    // Project's own session of the same name never takes the platform's
    // place, and it is never created: without it, nothing is sent.
    'system_session' => (string) env('NOTIFICATION_SYSTEM_SESSION', 'default'),
];
