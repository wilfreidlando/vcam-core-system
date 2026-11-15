<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

use Core\Support\Access\AccessScope;

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
    /**
     * The statement of one account over a period.
     *
     * The scope is **required** (ADR-0069 §2, guard G16): the console is
     * the first caller of this port — it was bound but never called until
     * ADR-0125 §7 opened the movements of an account. An account of a
     * project outside the scope yields an **empty page**, never another
     * tenant's ledger.
     */
    public function statement(AccountStatementCommand $command, AccessScope $scope): AccountStatementPage;
}
