<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

/**
 * One account of a project, as it is read.
 *
 * The account is designated by the composite `(kind, reference, currency)`
 * inside a project — the same way `AccountStatementCommand` designates it:
 * the id of an account is an implementation detail of the chart of
 * accounts, the composite is what a caller knows.
 *
 * Unlike `AccountBalanceView` (a line of the trial balance, which only
 * exists for the accounts that moved), an account view also describes an
 * account that never moved: its amounts are then zero, and the account is
 * still there — the chart of accounts of a project is not only its
 * history.
 */
final readonly class AccountView
{
    public function __construct(
        /** The nature of the account ('wallet', 'clearing', 'revenue'…) — open, never a closed list. */
        public string $kind,
        /** The kind-specific identifier (a wallet id, a provider code), or '' for a project-level account. */
        public string $reference,
        /** ISO 4217, upper case (G5). */
        public string $currency,
        /** Minor units — never a float (G5). */
        public int $debitMinor,
        /** Minor units — never a float (G5). */
        public int $creditMinor,
    ) {}

    /** Debits minus credits: positive on an asset (clearing), negative on a liability (wallet, revenue). */
    public function netDebitMinor(): int
    {
        return $this->debitMinor - $this->creditMinor;
    }
}
