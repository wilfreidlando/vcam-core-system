<?php

declare(strict_types=1);

namespace Core\Wallet\Infrastructure\Persistence\Eloquent;

use Core\Wallet\Domain\Exception\WalletOperationAlreadyExists;
use Core\Wallet\Domain\Model\WalletOperation;
use Core\Wallet\Domain\Port\WalletOperationRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EloquentWalletOperationRepository implements WalletOperationRepository
{
    public function save(WalletOperation $operation): void
    {
        try {
            // SAVEPOINT: always runs inside the use case's transaction (see
            // EloquentWalletRepository::save()).
            DB::transaction(function () use ($operation): void {
                WalletOperationRecord::query()->create([
                    'id' => $operation->id,
                    'project_id' => $operation->projectId,
                    'type' => $operation->type,
                    'idempotency_key' => $operation->idempotencyKey,
                    'currency' => $operation->currency,
                    'total_minor' => $operation->totalMinor,
                    'description' => $operation->description,
                    'metadata' => $operation->metadata,
                    'legs' => $operation->legs,
                    'external_reference' => $operation->externalReference,
                    'ledger_transaction_id' => $operation->ledgerTransactionId,
                    'created_at' => $operation->createdAt,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new WalletOperationAlreadyExists;
        }
    }

    public function findByIdempotencyKey(string $projectId, string $idempotencyKey): ?WalletOperation
    {
        $record = WalletOperationRecord::query()
            ->where('project_id', $projectId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $found = [];
        foreach (WalletOperationRecord::query()->whereKey($ids)->get() as $record) {
            $found[$record->id] = $this->toDomain($record);
        }

        return $found;
    }

    private function toDomain(WalletOperationRecord $record): WalletOperation
    {
        return new WalletOperation(
            $record->id,
            $record->project_id,
            $record->type,
            $record->idempotency_key,
            $record->currency,
            $record->total_minor,
            $record->description,
            $record->metadata,
            $record->legs,
            $record->external_reference,
            $record->ledger_transaction_id,
            $record->created_at->toDateTimeImmutable(),
        );
    }
}
