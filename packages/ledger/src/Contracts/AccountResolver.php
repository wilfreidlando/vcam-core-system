<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

/**
 * Resolve-or-create, race-safe (backed by the `(project_id, kind,
 * reference, currency)` unique constraint — see the migration). Callers
 * never mint an account id themselves.
 */
interface AccountResolver
{
    public function resolveOrCreate(ResolveOrCreateAccountCommand $command): AccountRef;
}
