<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

use Core\Support\Access\AccessScope;
use DateTimeImmutable;

/**
 * ADR-0076 §4 — every account of a project with its debit and credit
 * totals, optionally as at a moment (entries created strictly before it).
 * For each currency, the net debits of all accounts sum to zero: that is
 * the first invariant the hourly check verifies (ADR-0078 §2).
 */
interface TrialBalance
{
    /**
     * @return list<AccountBalanceView> ordered by currency, kind, reference
     */
    public function forProject(string $projectId, ?DateTimeImmutable $at = null): array;

    /**
     * The same, for the console: the operator's scope is **required** (ADR-0069 §2),
     * and a project outside it yields an empty balance, never a zero that looks real.
     *
     * @return list<AccountBalanceView>
     */
    public function forProjectScoped(string $projectId, AccessScope $scope, ?DateTimeImmutable $at = null): array;
}
