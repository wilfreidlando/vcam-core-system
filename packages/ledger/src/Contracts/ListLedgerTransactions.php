<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0076 §4 — a project's ledger transactions over a period, newest
 * first, keyset-paginated on (created_at, id): stable while new
 * transactions keep arriving.
 */
interface ListLedgerTransactions
{
    /**
     * The transactions of the project the command names.
     *
     * For a caller that already holds the project — a module checking
     * its own invariants, which knows which project it is checking.
     */
    public function list(ListLedgerTransactionsCommand $command): LedgerTransactionPage;

    /**
     * The same listing, narrowed to what the caller may see.
     *
     * ADR-0069 §2 / G16 — the scope is a REQUIRED parameter of every
     * read port the console uses: a port the console may call without
     * one reads everything. The scope is never a default value.
     */
    public function listScoped(ListLedgerTransactionsCommand $command, AccessScope $scope): LedgerTransactionPage;
}
