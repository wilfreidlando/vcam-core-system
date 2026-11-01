<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

/**
 * ADR-0076 §4 — the statement of ONE account over a period: the lines of
 * the period, the balance it opened on, the balance it closes on.
 *
 * The third read port of the ledger, next to `ListLedgerTransactions`
 * (the project's transactions) and `TrialBalance` (every account at a
 * date). Read-only by construction: a statement never writes anything.
 */
interface AccountStatement
{
    public function statement(AccountStatementCommand $command): AccountStatementPage;
}
