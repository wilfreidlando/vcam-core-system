<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Support;

use Core\Support\Integration\CorrelationIdProvider;

/**
 * Fixed-value `CorrelationIdProvider` for tests. Returns whatever the
 * test sets via `current()`; null by default.
 */
final class FakeCorrelationIdProvider implements CorrelationIdProvider
{
    public function __construct(private ?string $value = null) {}

    public function set(?string $value): void
    {
        $this->value = $value;
    }

    public function current(): ?string
    {
        return $this->value;
    }
}
