<?php

declare(strict_types=1);

namespace Core\Ledger\Infrastructure\Persistence\Eloquent;

use Core\Ledger\Domain\Exception\LedgerTransactionAlreadyExists;
use Core\Ledger\Domain\Model\Direction;
use Core\Ledger\Domain\Model\LedgerEntry;
use Core\Ledger\Domain\Model\LedgerTransaction;
use Core\Ledger\Domain\Port\LedgerTransactionRepository;
use Core\Support\Money\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EloquentLedgerTransactionRepository implements LedgerTransactionRepository
{
    public function save(LedgerTransaction $transaction): void
    {
        try {
            // SAVEPOINT (nested transaction) — always runs inside
            // PostLedgerTransaction's caller's outer transaction
            // (UnitOfWork). Without this, a caught violation poisons the
            // whole outer PostgreSQL transaction (same pattern as
            // EloquentWalletRepository::save()/EloquentPaymentRepository::create()).
            DB::transaction(function () use ($transaction): void {
                $record = LedgerTransactionRecord::query()->create([
                    'id' => $transaction->id,
                    'project_id' => $transaction->projectId,
                    'type' => $transaction->type,
                    'external_ref' => $transaction->externalRef,
                    'idempotency_key' => $transaction->idempotencyKey,
                ]);

                foreach ($transaction->entries as $entry) {
                    $record->entries()->create([
                        'account_id' => $entry->accountId,
                        'direction' => $entry->direction->value,
                        'amount_minor' => $entry->amount->amountMinor(),
                        'currency' => $entry->amount->currency(),
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw new LedgerTransactionAlreadyExists;
        }
    }

    public function findByIdempotencyKey(string $projectId, string $idempotencyKey): ?LedgerTransaction
    {
        $record = LedgerTransactionRecord::query()
            ->where('project_id', $projectId)
            ->where('idempotency_key', $idempotencyKey)
            ->with('entries')
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    private function toDomain(LedgerTransactionRecord $record): LedgerTransaction
    {
        return new LedgerTransaction(
            $record->id,
            $record->project_id,
            $record->type,
            $record->external_ref,
            $record->idempotency_key,
            array_values($record->entries->map(static fn (LedgerEntryRecord $entry): LedgerEntry => new LedgerEntry(
                $entry->account_id,
                Direction::from($entry->direction),
                Money::ofMinor($entry->amount_minor, $entry->currency),
            ))->all()),
        );
    }
}
