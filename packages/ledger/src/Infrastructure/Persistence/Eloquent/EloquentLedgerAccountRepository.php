<?php

declare(strict_types=1);

namespace Core\Ledger\Infrastructure\Persistence\Eloquent;

use Core\Ledger\Domain\Exception\AccountAlreadyExists;
use Core\Ledger\Domain\Model\AccountKind;
use Core\Ledger\Domain\Model\LedgerAccount;
use Core\Ledger\Domain\Port\LedgerAccountRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EloquentLedgerAccountRepository implements LedgerAccountRepository
{
    public function save(LedgerAccount $account): void
    {
        try {
            // Nested DB::transaction() = SAVEPOINT when already inside one
            // (this always runs inside Wallet's outer transaction). Without
            // it, catching the violation below would leave that outer
            // transaction poisoned for every query after it.
            DB::transaction(function () use ($account): void {
                LedgerAccountRecord::query()->create([
                    'id' => $account->id,
                    'project_id' => $account->projectId,
                    'kind' => (string) $account->kind,
                    'reference' => $account->reference,
                    'currency' => $account->currency,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new AccountAlreadyExists;
        }
    }

    public function find(string $id): ?LedgerAccount
    {
        $record = LedgerAccountRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByComposite(string $projectId, AccountKind $kind, string $reference, string $currency): ?LedgerAccount
    {
        $record = LedgerAccountRecord::query()
            ->where('project_id', $projectId)
            ->where('kind', (string) $kind)
            ->where('reference', $reference)
            ->where('currency', $currency)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    private function toDomain(LedgerAccountRecord $record): LedgerAccount
    {
        return new LedgerAccount(
            $record->id,
            $record->project_id,
            AccountKind::fromString($record->kind),
            $record->reference,
            $record->currency,
        );
    }
}
