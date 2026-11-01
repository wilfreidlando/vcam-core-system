<?php

declare(strict_types=1);

namespace Core\Ledger\Tests\Unit\Fakes;

use Core\Ledger\Domain\Model\PostedLedgerEntry;
use Core\Ledger\Domain\Port\LedgerEntryRepository;

final class InMemoryLedgerEntryRepository implements LedgerEntryRepository
{
    /** @var array<string, list<PostedLedgerEntry>> */
    private array $entriesByAccount = [];

    /**
     * The project each account belongs to — the SQL side resolves it with
     * a subquery on `ledger_accounts` (ADR-0069 §2); the fake needs to
     * know it to model the same filter.
     *
     * @var array<string, string>
     */
    private array $projectByAccount = [];

    public function put(string $accountId, PostedLedgerEntry $entry, ?string $projectId = null): void
    {
        $this->entriesByAccount[$accountId][] = $entry;

        if ($projectId !== null) {
            $this->projectByAccount[$accountId] = $projectId;
        }
    }

    public function findByAccountPaginated(string $accountId, int $page, int $perPage, ?string $projectId = null): array
    {
        // ADR-0069 §2 — the entries of an account of another project are
        // never returned: the filter is applied before the rows are read.
        if ($projectId !== null && ($this->projectByAccount[$accountId] ?? null) !== $projectId) {
            return ['items' => [], 'total' => 0];
        }

        $all = $this->entriesByAccount[$accountId] ?? [];
        $sorted = collect($all)->sortByDesc(static fn (PostedLedgerEntry $entry): string => $entry->createdAt->format('Y-m-d\TH:i:s.u').'-'.$entry->id)->values();

        $page = max(1, $page);
        $items = array_values($sorted->slice(($page - 1) * $perPage, $perPage)->values()->all());

        return ['items' => $items, 'total' => $sorted->count()];
    }
}
