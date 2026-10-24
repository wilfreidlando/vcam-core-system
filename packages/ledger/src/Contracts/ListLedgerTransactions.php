<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

/**
 * ADR-0076 §4 — a project's ledger transactions over a period, newest
 * first, keyset-paginated on (created_at, id): stable while new
 * transactions keep arriving.
 */
interface ListLedgerTransactions
{
    public function list(ListLedgerTransactionsCommand $command): LedgerTransactionPage;
}
