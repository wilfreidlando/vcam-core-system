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
     * @param string|null $projectId when given, only the entries of an
     *                               account of that project are
     *                               returned (ADR-0069 §2). It is applied
     *                               in the query, so the entries of
     *                               another tenant are never
     *                               materialised.
     * @return array{items: list<PostedLedgerEntry>, total: int}
     */
    public function findByAccountPaginated(string $accountId, int $page, int $perPage, ?string $projectId = null): array;
}
