<?php

declare(strict_types=1);

namespace Core\Support;

use DateTimeImmutable;

/**
 * Time as a dependency. Domain layers depend on this port rather than on `now()`, so
 * time-sensitive rules (fee effective dates, holds expiry, retry backoff, subscription
 * renewals) are deterministically testable.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
