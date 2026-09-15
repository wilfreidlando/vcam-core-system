<?php

declare(strict_types=1);

namespace Core\Ledger\Tests\Unit\Fakes;

use Core\Ledger\Domain\Exception\AccountAlreadyExists;
use Core\Ledger\Domain\Model\AccountKind;
use Core\Ledger\Domain\Model\LedgerAccount;
use Core\Ledger\Domain\Port\LedgerAccountRepository;

final class InMemoryLedgerAccountRepository implements LedgerAccountRepository
{
    /**
     * When set, the next save() throws AccountAlreadyExists instead of
     * persisting, and this winning row becomes visible immediately
     * afterwards — models a concurrent resolve-or-create race.
     */
    public ?LedgerAccount $concurrentWinner = null;

    /** @var array<string, LedgerAccount> */
    private array $accounts = [];

    public function save(LedgerAccount $account): void
    {
        if ($this->concurrentWinner !== null) {
            $winner = $this->concurrentWinner;
            $this->concurrentWinner = null;
            $this->accounts[$winner->id] = $winner;

            throw new AccountAlreadyExists;
        }

        $this->accounts[$account->id] = $account;
    }

    public function find(string $id): ?LedgerAccount
    {
        return $this->accounts[$id] ?? null;
    }

    public function findByComposite(string $projectId, AccountKind $kind, string $reference, string $currency): ?LedgerAccount
    {
        foreach ($this->accounts as $account) {
            if ($account->projectId === $projectId
                && $account->kind->equals($kind)
                && $account->reference === $reference
                && $account->currency === $currency
            ) {
                return $account;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->accounts);
    }
}
