<?php

declare(strict_types=1);

namespace Core\Wallet\Infrastructure\Persistence\Eloquent;

use Core\Support\Money\Money;
use Core\Wallet\Domain\Exception\WalletNotFound;
use Core\Wallet\Domain\Model\WalletBalance;
use Core\Wallet\Domain\Port\WalletBalanceRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EloquentWalletBalanceRepository implements WalletBalanceRepository
{
    public function initialize(string $walletId, string $currency): void
    {
        try {
            // SAVEPOINT: see EloquentWalletRepository — the caller that
            // reaches this has already raced once (on the wallet row) and
            // won; this second insert cannot conflict under normal
            // operation, but the same defensive pattern costs nothing.
            DB::transaction(function () use ($walletId): void {
                WalletBalanceRecord::query()->create([
                    'wallet_id' => $walletId,
                    'available_minor' => 0,
                    'held_minor' => 0,
                    'version' => 0,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Already initialised — nothing to do.
        }
    }

    public function find(string $walletId): ?WalletBalance
    {
        $record = WalletBalanceRecord::query()->where('wallet_id', $walletId)->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findMany(array $walletIds): array
    {
        if ($walletIds === []) {
            return [];
        }

        // `whereIn()`/`pluck()` are forwarded by Eloquent\Builder to the
        // base query builder rather than declared on it directly — same
        // Larastan false positive as the toBase()/hydrate() workaround
        // used elsewhere in this codebase (e.g. EloquentAuditQuery).
        $rows = WalletBalanceRecord::query()->toBase()
            ->whereIn('wallet_id', $walletIds)
            ->get();

        /** @var array<string, string> $currencies */
        $currencies = WalletRecord::query()->toBase()
            ->whereIn('id', $walletIds)
            ->pluck('currency', 'id')
            ->all();

        $balances = [];
        foreach ($rows as $row) {
            $walletId = $row->wallet_id;
            $availableMinor = $row->available_minor;
            $heldMinor = $row->held_minor;
            $version = $row->version;
            assert(is_string($walletId));
            assert(is_int($availableMinor));
            assert(is_int($heldMinor));
            assert(is_int($version));

            $currency = $currencies[$walletId] ?? null;
            assert(is_string($currency));

            $balances[$walletId] = new WalletBalance(
                $walletId,
                Money::ofMinor($availableMinor, $currency),
                Money::ofMinor($heldMinor, $currency),
                $version,
            );
        }

        return $balances;
    }

    public function lockForUpdate(string $walletId): WalletBalance
    {
        // Eloquent forwards lockForUpdate() to the query builder via __call;
        // its `@method static` docblock is a Laravel-wide documentation
        // convention, not a real static method — every fluent builder call
        // would trigger the same warning.
        // @phpstan-ignore staticMethod.dynamicCall
        $record = WalletBalanceRecord::query()
            ->where('wallet_id', $walletId)
            ->lockForUpdate()
            ->first();

        if ($record === null) {
            throw WalletNotFound::withId($walletId);
        }

        return $this->toDomain($record);
    }

    public function save(WalletBalance $balance): void
    {
        WalletBalanceRecord::query()
            ->where('wallet_id', $balance->walletId)
            ->update([
                'available_minor' => $balance->available->amountMinor(),
                'held_minor' => $balance->held->amountMinor(),
                'version' => $balance->version,
                'updated_at' => now(),
            ]);
    }

    private function toDomain(WalletBalanceRecord $record): WalletBalance
    {
        // The balance row carries no currency of its own (spec technique
        // §6.3) — it belongs to exactly one wallet, whose currency is
        // authoritative. The caller (Application layer) already knows it;
        // Money still needs *a* currency to construct, so this reads it
        // from the owning wallet the one time it's needed.
        $currency = WalletRecord::query()->whereKey($record->wallet_id)->value('currency');
        assert(is_string($currency));

        return new WalletBalance(
            $record->wallet_id,
            Money::ofMinor($record->available_minor, $currency),
            Money::ofMinor($record->held_minor, $currency),
            $record->version,
        );
    }
}
