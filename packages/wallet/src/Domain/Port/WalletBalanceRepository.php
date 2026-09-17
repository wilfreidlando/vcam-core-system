<?php

declare(strict_types=1);

namespace Core\Wallet\Domain\Port;

use Core\Wallet\Domain\Model\WalletBalance;

interface WalletBalanceRepository
{
    public function initialize(string $walletId, string $currency): void;

    /**
     * Plain, non-locking read — for the read-only balance endpoint. Never
     * use this where the result feeds a decision to mutate: use
     * `lockForUpdate()` there, or two concurrent mutations can both act on
     * the same stale snapshot.
     */
    public function find(string $walletId): ?WalletBalance;

    /**
     * Bulk read for a listing page (ADR-0045's `ListWallets`, follow-up
     * fix): one query for every wallet's balance instead of `find()`
     * called once per wallet in a loop. Keyed by `walletId`; a wallet with
     * no balance row simply has no entry — the caller decides what that
     * means (it's an anomaly per `CreditWalletUseCase`'s invariant that
     * every wallet is `initialize()`d in the same transaction it's
     * created in, never a normal state), this port only reports what
     * exists.
     *
     * @param list<string> $walletIds
     * @return array<string, WalletBalance>
     */
    public function findMany(array $walletIds): array;

    /**
     * `SELECT ... FOR UPDATE` (ADR-0006): serialises concurrent mutations of
     * the same wallet. Must be called inside an open database transaction —
     * the lock is released at COMMIT/ROLLBACK.
     */
    public function lockForUpdate(string $walletId): WalletBalance;

    public function save(WalletBalance $balance): void;
}
