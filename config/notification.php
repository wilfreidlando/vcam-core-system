<?php

declare(strict_types=1);

return [
    // ADR-0062 / decision D5: a notification's content and variables
    // (recipient phone number…) are visible to authorized operators for
    // this many days, then emptied by `notification:redact-expired`.
    'content_retention_days' => (int) env('NOTIFICATION_CONTENT_RETENTION_DAYS', 90),
];
