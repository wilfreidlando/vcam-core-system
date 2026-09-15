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

    public function findByProject(string $projectId, int $page, int $perPage): array
    {
        $matching = array_values(array_filter(
            $this->wallets,
            static fn (Wallet $wallet): bool => $wallet->projectId === $projectId,
        ));

        $total = count($matching);
        $offset = ($page - 1) * $perPage;

        return ['items' => array_slice($matching, $offset, $perPage), 'total' => $total];
    }
}
