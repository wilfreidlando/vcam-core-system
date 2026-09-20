<?php

declare(strict_types=1);

namespace Core\Ledger\Tests\Unit\Fakes;

use Core\Ledger\Domain\Exception\LedgerTransactionAlreadyExists;
use Core\Ledger\Domain\Model\LedgerTransaction;
use Core\Ledger\Domain\Port\LedgerTransactionRepository;

final class InMemoryLedgerTransactionRepository implements LedgerTransactionRepository
{
    /**
     * When set, `save()` throws `LedgerTransactionAlreadyExists` instead
     * of persisting — simulates a concurrent call that committed first,
     * between this caller's own `findByIdempotencyKey()` read and its
     * `save()` (the exact race `PostLedgerTransaction::post()`'s catch
     * block exists to recover from).
     */
    public ?LedgerTransaction $concurrentWinner = null;

    /** @var array<string, LedgerTransaction> */
    private array $transactions = [];

    public function save(LedgerTransaction $transaction): void
    {
        if ($this->concurrentWinner !== null) {
            $this->transactions[$this->concurrentWinner->id] = $this->concurrentWinner;
            throw new LedgerTransactionAlreadyExists;
        }

        $this->transactions[$transaction->id] = $transaction;
    }

    public function findByIdempotencyKey(string $projectId, string $idempotencyKey): ?LedgerTransaction
    {
        foreach ($this->transactions as $transaction) {
            if ($transaction->projectId === $projectId && $transaction->idempotencyKey === $idempotencyKey) {
                return $transaction;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->transactions);
    }
}
