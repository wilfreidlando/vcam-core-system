<?php

declare(strict_types=1);

namespace Core\Ledger\Infrastructure\Persistence\Eloquent;

use Core\Ledger\Domain\Model\Direction;
use Core\Ledger\Domain\Model\PostedLedgerEntry;
use Core\Ledger\Domain\Port\LedgerEntryRepository;
use Core\Support\Money\Money;
use DateTimeImmutable;

final class EloquentLedgerEntryRepository implements LedgerEntryRepository
{
    public function findByAccountPaginated(string $accountId, int $page, int $perPage): array
    {
        $query = LedgerEntryRecord::query()->where('account_id', $accountId);

        // `orderBy`/`skip`/`take`/`count` are forwarded by Eloquent\Builder
        // to the underlying query builder rather than declared on it
        // directly; going through `toBase()` first avoids a Larastan false
        // positive ("dynamic call to static method") on those forwarded
        // calls, and `LedgerEntryRecord::hydrate()` restores the model
        // casts after — same pattern used throughout the Core's other
        // paginated repositories.
        $base = $query->toBase();
        $total = (clone $base)->count();

        // Tiebreaker on the primary key — entries sharing the same
        // `created_at` (entirely possible: a transaction's several
        // entries are inserted within the same statement) would otherwise
        // duplicate or vanish across pages.
        $base->orderBy('created_at', 'desc');
        $base->orderBy('id', 'desc');
        $base->skip(($page - 1) * $perPage);
        $base->take($perPage);

        $rows = $base->get()->map(static fn (object $row): array => (array) $row)->all();
        $records = LedgerEntryRecord::hydrate($rows);

        // Each entry's transaction context (type, external_ref) is fetched
        // as one batched second query rather than an Eloquent `with()`
        // eager load — `hydrate()` above already discards any relation
        // that would have carried, so this is simpler than reconstructing
        // one. `whereIn` goes through `toBase()` too, for the same
        // forwarded-static-method reason as above.
        /** @var list<string> $transactionIds */
        $transactionIds = array_values(array_unique(array_map(
            static fn (LedgerEntryRecord $record): string => $record->transaction_id,
            $records->all(),
        )));

        /** @var array<string, array{type: string, external_ref: string|null}> $transactionsById */
        $transactionsById = [];
        foreach (LedgerTransactionRecord::query()->toBase()->whereIn('id', $transactionIds)->get() as $row) {
            $id = $row->id;
            $type = $row->type;
            $externalRef = $row->external_ref;
            if (! is_string($id) || ! is_string($type)) {
                continue;
            }
            $transactionsById[$id] = ['type' => $type, 'external_ref' => is_string($externalRef) ? $externalRef : null];
        }

        $items = [];
        foreach ($records as $record) {
            $transaction = $transactionsById[$record->transaction_id] ?? ['type' => '', 'external_ref' => null];

            $items[] = new PostedLedgerEntry(
                id: $record->id,
                transactionId: $record->transaction_id,
                transactionType: $transaction['type'],
                externalRef: $transaction['external_ref'],
                direction: Direction::from($record->direction),
                amount: Money::ofMinor($record->amount_minor, $record->currency),
                // `hydrate()` from raw `toBase()` rows doesn't reliably
                // apply the model's configured immutable-date cast (it did
                // here, but returned `Illuminate\Support\Carbon`, not
                // `CarbonImmutable`) — normalising through
                // `createFromInterface()` is correct regardless of which
                // `DateTimeInterface` implementation comes back.
                createdAt: DateTimeImmutable::createFromInterface($record->created_at),
            );
        }

        return ['items' => $items, 'total' => $total];
    }
}
