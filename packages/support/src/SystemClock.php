<?php

declare(strict_types=1);

namespace Core\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The real clock. Always UTC: every timestamp the Core persists or compares is
 * UTC, and local time is a presentation concern.
 */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
