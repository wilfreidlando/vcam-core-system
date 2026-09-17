<?php

declare(strict_types=1);

namespace Core\Wallet\Infrastructure\Persistence\Eloquent;

use Core\Wallet\Domain\Exception\WalletAlreadyExists;
use Core\Wallet\Domain\Model\Wallet;
use Core\Wallet\Domain\Port\WalletRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EloquentWalletRepository implements WalletRepository
{
    public function save(Wallet $wallet): void
    {
        try {
            // SAVEPOINT (nested transaction) — this always runs inside
            // CreditWalletUseCase's outer transaction. See the identical
            // comment in EloquentLedgerAccountRepository for why this
            // matters: without it, a caught violation poisons the whole
            // outer transaction under PostgreSQL.
            DB::transaction(function () use ($wallet): void {
                WalletRecord::query()->create([
                    'id' => $wallet->id,
                    'project_id' => $wallet->projectId,
                    'party_id' => $wallet->partyId,
                    'currency' => $wallet->currency,
                    'status' => $wallet->status,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new WalletAlreadyExists;
        }
    }

    public function find(string $id): ?Wallet
    {
        $record = WalletRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByPartyAndCurrency(string $partyId, string $currency): ?Wallet
    {
        $record = WalletRecord::query()
            ->where('party_id', $partyId)
            ->where('currency', $currency)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByProject(string $projectId, int $page, int $perPage): array
    {
        // `orderBy`/`skip`/`take`/`count` are forwarded by Eloquent\Builder
        // to the underlying query builder rather than declared on it
        // directly; going through `toBase()` first avoids a Larastan false
        // positive ("dynamic call to static method") on those forwarded
        // calls, and `WalletRecord::hydrate()` restores the casts after.
        $base = WalletRecord::query()->where('project_id', $projectId)->toBase();

        $total = (clone $base)->count();

        // Tiebreaker on the primary key — see EloquentNotificationRepository's
        // identical comment: without it, rows sharing a created_at can
        // duplicate or vanish across pages.
        $base->orderBy('created_at', 'desc');
        $base->orderBy('id', 'desc');
        $base->skip(($page - 1) * $perPage);
        $base->take($perPage);

        $rows = $base->get()->map(static fn (object $row): array => (array) $row)->all();

        $records = WalletRecord::hydrate($rows);

        $wallets = [];
        foreach ($records as $record) {
            $wallets[] = $this->toDomain($record);
        }

        return ['items' => $wallets, 'total' => $total];
    }

    private function toDomain(WalletRecord $record): Wallet
    {
        return new Wallet($record->id, $record->project_id, $record->party_id, $record->currency, $record->status);
    }
}
