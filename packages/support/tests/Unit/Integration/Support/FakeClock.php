<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Support;

use Core\Support\Clock;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Test clock — time is set manually and advances by `advance()`.
 */
final class FakeClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(?DateTimeImmutable $start = null)
    {
        $this->now = ($start ?? new DateTimeImmutable('2026-01-01T00:00:00Z'))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(DateInterval $interval): void
    {
        $this->now = $this->now->add($interval);
    }

    public function setTo(DateTimeImmutable $when): void
    {
        $this->now = $when->setTimezone(new DateTimeZone('UTC'));
    }
}
