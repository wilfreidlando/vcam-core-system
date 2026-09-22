<?php

declare(strict_types=1);

namespace Core\Ledger\Tests\Unit\Fakes;

use Core\Ledger\Domain\Model\PostedLedgerEntry;
use Core\Ledger\Domain\Port\LedgerEntryRepository;

final class InMemoryLedgerEntryRepository implements LedgerEntryRepository
{
    /** @var array<string, list<PostedLedgerEntry>> */
    private array $entriesByAccount = [];

    public function put(string $accountId, PostedLedgerEntry $entry): void
    {
        $this->entriesByAccount[$accountId][] = $entry;
    }

    public function findByAccountPaginated(string $accountId, int $page, int $perPage): array
    {
        $all = $this->entriesByAccount[$accountId] ?? [];
        $sorted = collect($all)->sortByDesc(static fn (PostedLedgerEntry $entry): string => $entry->createdAt->format('Y-m-d\TH:i:s.u').'-'.$entry->id)->values();

        $page = max(1, $page);
        $items = array_values($sorted->slice(($page - 1) * $perPage, $perPage)->values()->all());

        return ['items' => $items, 'total' => $sorted->count()];
    }
}
