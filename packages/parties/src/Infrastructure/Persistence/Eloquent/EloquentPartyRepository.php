<?php

declare(strict_types=1);

namespace Core\Parties\Infrastructure\Persistence\Eloquent;

use Core\Parties\Domain\Exception\PartyAlreadyExists;
use Core\Parties\Domain\Model\Party;
use Core\Parties\Domain\Port\PartyRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EloquentPartyRepository implements PartyRepository
{
    public function save(Party $party): void
    {
        try {
            // A nested DB::transaction() becomes a SAVEPOINT when a
            // transaction is already open (Wallet's lazy party lookups may
            // run inside one). Without it, catching the violation below
            // would leave the OUTER transaction poisoned — PostgreSQL
            // refuses every further query on it until a rollback, savepoint
            // or not.
            DB::transaction(function () use ($party): void {
                PartyRecord::query()->create([
                    'id' => $party->id,
                    'project_id' => $party->projectId,
                    'external_ref' => $party->externalRef,
                    'type' => $party->type(),
                    'kyc_status' => $party->kycStatus(),
                    'metadata' => $party->metadata(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw PartyAlreadyExists::forExternalRef($party->projectId, $party->externalRef);
        }
    }

    public function find(string $id): ?Party
    {
        $record = PartyRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByExternalRef(string $projectId, string $externalRef): ?Party
    {
        $record = PartyRecord::query()
            ->where('project_id', $projectId)
            ->where('external_ref', $externalRef)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    private function toDomain(PartyRecord $record): Party
    {
        return new Party(
            $record->id,
            $record->project_id,
            $record->external_ref,
            $record->type,
            $record->kyc_status,
            $record->metadata,
        );
    }
}
