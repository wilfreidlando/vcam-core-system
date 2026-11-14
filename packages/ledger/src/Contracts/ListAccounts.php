<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0125 §6 — the accounts of one project, **all of them**, including
 * the ones that never moved.
 *
 * The trial balance (`TrialBalance`) only lists the accounts that carry a
 * movement: it is a balance, not a chart of accounts. The console needs
 * both — the balance says where the project stands, the chart says which
 * accounts exist and who owns their money.
 *
 * Read-only by construction: no account is created here (ADR-0033 §4 —
 * the Core creates the accounts the modules need, the operator never
 * picks a nature).
 */
interface ListAccounts
{
    /**
     * ADR-0069 §2 / G16 — the scope is a required parameter (never a
     * default): a caller that may not see the project reads nothing at
     * all, never another project's accounts.
     *
     * @return list<AccountView> the accounts of the project, ordered by nature, then reference, then currency
     */
    public function accountsOf(string $projectId, AccessScope $scope): array;
}
