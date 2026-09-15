<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Core\Support\UnitOfWork;
use Illuminate\Support\Facades\DB;

/**
 * Lives in the application shell, not `core/support` (G3d: the shared
 * kernel holds no persistence). `app/` is the composition root — it may
 * depend on the framework and on bounded contexts; nothing may depend on it.
 */
final class DatabaseUnitOfWork implements UnitOfWork
{
    public function transactional(callable $work): mixed
    {
        return DB::transaction(static fn (): mixed => $work());
    }
}
