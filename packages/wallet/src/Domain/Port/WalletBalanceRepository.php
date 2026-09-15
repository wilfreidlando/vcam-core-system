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
     * `SELECT ... FOR UPDATE` (ADR-0006): serialises concurrent mutations of
     * the same wallet. Must be called inside an open database transaction —
     * the lock is released at COMMIT/ROLLBACK.
     */
    public function lockForUpdate(string $walletId): WalletBalance;

    public function save(WalletBalance $balance): void;
}
