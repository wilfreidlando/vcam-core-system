<?php

declare(strict_types=1);

namespace Tests\Support;

use Core\Support\Clock;
use DateTimeImmutable;

/**
 * A clock the test drives: it shows the moment the test sets.
 */
final class MutableClock implements Clock
{
    public function __construct(public DateTimeImmutable $moment) {}

    public function now(): DateTimeImmutable
    {
        return $this->moment;
    }
}
