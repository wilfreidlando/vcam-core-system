<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

interface ListAccountEntries
{
    /**
     * An account that was never created (nothing ever posted to it) is
     * not an error — this returns an empty page, `total: 0`.
     */
    public function list(ListAccountEntriesCommand $command): LedgerEntryPage;
}
