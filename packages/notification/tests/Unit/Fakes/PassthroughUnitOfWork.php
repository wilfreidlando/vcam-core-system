<?php

declare(strict_types=1);

namespace Core\Notification\Tests\Unit\Fakes;

use Core\Support\UnitOfWork;

/**
 * No real transaction: the fakes in this namespace are plain in-memory
 * collections, so there is nothing to commit or roll back. Real
 * transactionality is proven separately, against PostgreSQL, in
 * packages/notification/tests/Integration.
 */
final class PassthroughUnitOfWork implements UnitOfWork
{
    public function transactional(callable $work): mixed
    {
        return $work();
    }
}
