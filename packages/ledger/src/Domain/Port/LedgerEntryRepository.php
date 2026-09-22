<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Port;

use Core\Ledger\Domain\Model\PostedLedgerEntry;

interface LedgerEntryRepository
{
    /**
     * Newest first. An account with no history yet (or that was never
     * created) is not an error — it's an empty page.
     *
     * @return array{items: list<PostedLedgerEntry>, total: int}
     */
    public function findByAccountPaginated(string $accountId, int $page, int $perPage): array;
}
