<?php

declare(strict_types=1);

namespace Core\Wallet\Tests\Unit\Fakes;

use Core\Wallet\Domain\Exception\WalletAlreadyExists;
use Core\Wallet\Domain\Model\Wallet;
use Core\Wallet\Domain\Port\WalletRepository;

final class InMemoryWalletRepository implements WalletRepository
{
    /**
     * When set, the next save() throws WalletAlreadyExists instead of
     * persisting, and this winning row becomes visible immediately
     * afterwards — models a concurrent lazy-creation race.
     */
    public ?Wallet $concurrentWinner = null;

    /** @var array<string, Wallet> */
    private array $wallets = [];

    public function save(Wallet $wallet): void
    {
        if ($this->concurrentWinner !== null) {
            $winner = $this->concurrentWinner;
            $this->concurrentWinner = null;
            $this->wallets[$winner->id] = $winner;

            throw new WalletAlreadyExists;
        }

        $this->wallets[$wallet->id] = $wallet;
    }

    public function find(string $id): ?Wallet
    {
        return $this->wallets[$id] ?? null;
    }

    public function findByPartyAndCurrency(string $partyId, string $currency): ?Wallet
    {
        foreach ($this->wallets as $wallet) {
            if ($wallet->partyId === $partyId && $wallet->currency === $currency) {
                return $wallet;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->wallets);
    }
}
