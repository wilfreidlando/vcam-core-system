<?php

declare(strict_types=1);

namespace Core\Support\Idempotency;

/**
 * Supplies the scope an `Idempotency-Key` is unique within — a Project
 * id in practice, but `core/support` may not depend on `core/identity`
 * (the shared kernel has zero allowed module dependencies) to know that
 * directly. The implementation, which reads `Core\Identity\Contracts\ProjectContext`,
 * lives in `app/Infrastructure/` — the same split as `Core\Support\UnitOfWork`.
 */
interface IdempotencyScope
{
    public function current(): string;
}
