<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Unit\Fakes;

use Core\Support\Clock;
use DateTimeImmutable;

final class FixedClock implements Clock
{
    public function __construct(
        private readonly DateTimeImmutable $now = new DateTimeImmutable('2026-09-15T10:00:00Z'),
    ) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
