<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Payment\Domain\Port\MoneyPoolLock;

/**
 * A `MoneyPoolLock` that only remembers what it was asked to lock: the
 * unit tests are single-threaded, the advisory lock itself is proved
 * against PostgreSQL in the integration tests.
 */
final class FakeMoneyPoolLock implements MoneyPoolLock
{
    /** @var list<array{projectId: string, currency: string}> */
    public array $locked = [];

    public function lockPool(string $projectId, string $currency): void
    {
        $this->locked[] = ['projectId' => $projectId, 'currency' => $currency];
    }
}
