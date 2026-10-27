<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Port;

use Core\Ledger\Domain\Model\LedgerTransaction;

interface LedgerTransactionRepository
{
    /**
     * Persists the transaction and all of its entries. Implementations must
     * do this inside a single database transaction — the deferred balance
     * trigger only evaluates at COMMIT.
     */
    public function save(LedgerTransaction $transaction): void;

    public function findByIdempotencyKey(string $projectId, string $idempotencyKey): ?LedgerTransaction;

    /**
     * The transaction of this id, whatever its project — the caller
     * checks the project itself (a correction may only counter-pass a
     * transaction of its own project, and saying so is the caller's
     * rule, not the repository's).
     */
    public function findById(string $id): ?LedgerTransaction;
}
