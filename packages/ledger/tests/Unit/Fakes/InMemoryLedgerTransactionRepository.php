<?php

declare(strict_types=1);

namespace Core\Ledger\Tests\Unit\Fakes;

use Core\Ledger\Domain\Model\LedgerTransaction;
use Core\Ledger\Domain\Port\LedgerTransactionRepository;

final class InMemoryLedgerTransactionRepository implements LedgerTransactionRepository
{
    /** @var array<string, LedgerTransaction> */
    private array $transactions = [];

    public function save(LedgerTransaction $transaction): void
    {
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
