<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

use Core\Support\Access\AccessScope;

interface ListAccountEntries
{
    /**
     * An account that was never created (nothing ever posted to it) is
     * not an error — this returns an empty page, `total: 0`.
     *
     * The scope is required (ADR-0069 §2): the entries of an account of a
     * project outside the scope are never returned — an empty page, never
     * another tenant's ledger.
     */
    public function list(ListAccountEntriesCommand $command, AccessScope $scope): LedgerEntryPage;
}
