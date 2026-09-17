<?php

declare(strict_types=1);

namespace Core\Wallet\Tests\Unit\Fakes;

use Core\Support\Money\Money;
use Core\Wallet\Domain\Model\WalletBalance;
use Core\Wallet\Domain\Port\WalletBalanceRepository;
use RuntimeException;

final class InMemoryWalletBalanceRepository implements WalletBalanceRepository
{
    /** @var array<string, WalletBalance> */
    private array $balances = [];

    public function initialize(string $walletId, string $currency): void
    {
        if (isset($this->balances[$walletId])) {
            return;
        }

        $this->balances[$walletId] = new WalletBalance($walletId, Money::zero($currency), Money::zero($currency), 0);
    }

    public function find(string $walletId): ?WalletBalance
    {
        return $this->balances[$walletId] ?? null;
    }

    public function findMany(array $walletIds): array
    {
        $result = [];
        foreach ($walletIds as $walletId) {
            if (isset($this->balances[$walletId])) {
                $result[$walletId] = $this->balances[$walletId];
            }
        }

        return $result;
    }

    public function lockForUpdate(string $walletId): WalletBalance
    {
        return $this->balances[$walletId]
            ?? throw new RuntimeException("No balance initialised for wallet {$walletId}.");
    }

    public function save(WalletBalance $balance): void
    {
        $this->balances[$balance->walletId] = $balance;
    }
}
