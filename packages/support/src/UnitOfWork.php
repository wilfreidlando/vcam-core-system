<?php

declare(strict_types=1);

namespace Core\Support;

/**
 * A port rather than `DB::transaction()` called inline: Application layers
 * depend on Domain and Contracts only, never Illuminate directly (see any
 * module's README). A financial mutation (Wallet credit/debit) needs the
 * ledger entries, the materialised balance, and the outbox event to commit
 * or roll back together — this is how that atomicity is expressed without
 * coupling the use case to the framework.
 */
interface UnitOfWork
{
    /**
     * @template T
     *
     * @param callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed;
}
