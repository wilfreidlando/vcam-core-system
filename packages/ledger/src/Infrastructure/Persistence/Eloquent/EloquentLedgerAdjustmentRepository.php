<?php

declare(strict_types=1);

namespace Core\Ledger\Infrastructure\Persistence\Eloquent;

use Core\Ledger\Domain\Model\AccountKind;
use Core\Ledger\Domain\Model\LedgerAdjustment;
use Core\Ledger\Domain\Model\LedgerAdjustmentStatus;
use Core\Ledger\Domain\Port\LedgerAdjustmentRepository;
use DateTimeImmutable;

/**
 * The workflow table of the operating corrections — NOT an append-only
 * book: a correction changes state (proposed → approved / rejected), the
 * way `payment`'s money exceptions do. The immutability of the ledger
 * itself is untouched: only the correction's own row moves, and the
 * money it moves is written to `ledger_transactions`/`ledger_entries`,
 * which no trigger ever lets change.
 */
final class EloquentLedgerAdjustmentRepository implements LedgerAdjustmentRepository
{
    public function create(LedgerAdjustment $adjustment): void
    {
        LedgerAdjustmentRecord::query()->create($this->toAttributes($adjustment));
    }

    public function save(LedgerAdjustment $adjustment): void
    {
        $attributes = $this->toAttributes($adjustment);
        unset($attributes['id'], $attributes['created_at']);

        LedgerAdjustmentRecord::query()->whereKey($adjustment->id)->toBase()->update($attributes);
    }

    public function find(string $id): ?LedgerAdjustment
    {
        $record = LedgerAdjustmentRecord::query()->find($id);

        return $record instanceof LedgerAdjustmentRecord ? $this->toDomain($record) : null;
    }

    public function findForUpdate(string $id): ?LedgerAdjustment
    {
        $row = LedgerAdjustmentRecord::query()->whereKey($id)->toBase()->lockForUpdate()->first();
        if ($row === null) {
            return null;
        }

        return $this->toDomain(LedgerAdjustmentRecord::hydrate([(array) $row])->sole());
    }

    public function list(?array $projectIds, ?LedgerAdjustmentStatus $status, int $page, int $perPage): array
    {
        $base = LedgerAdjustmentRecord::query()->toBase();

        if ($projectIds !== null) {
            $base->whereIn('project_id', $projectIds);
        }
        if ($status !== null) {
            $base->where('status', $status->value);
        }

        $total = (clone $base)->count();

        // What an operator must look at comes first: the corrections
        // waiting for a second pair of eyes, then the newest.
        $base->orderByRaw("CASE status WHEN 'proposed' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END");
        $base->orderBy('created_at', 'desc');
        $base->orderBy('id', 'desc');
        $base->skip(($page - 1) * $perPage);
        $base->take($perPage);

        $rows = $base->get()->map(static fn (object $row): array => (array) $row)->all();

        $items = [];
        foreach (LedgerAdjustmentRecord::hydrate($rows) as $record) {
            $items[] = $this->toDomain($record);
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(LedgerAdjustment $adjustment): array
    {
        return [
            'id' => $adjustment->id,
            'project_id' => $adjustment->projectId,
            'source_kind' => (string) $adjustment->sourceKind,
            'source_reference' => $adjustment->sourceReference,
            'destination_kind' => (string) $adjustment->destinationKind,
            'destination_reference' => $adjustment->destinationReference,
            'amount_minor' => $adjustment->amountMinor,
            'currency' => $adjustment->currency,
            'reason' => $adjustment->reason,
            'status' => $adjustment->status->value,
            'proposed_by' => $adjustment->proposedBy,
            'approved_by' => $adjustment->approvedBy,
            'ledger_transaction_id' => $adjustment->ledgerTransactionId,
            'reverses_transaction_id' => $adjustment->reversesTransactionId,
            'created_at' => $adjustment->createdAt,
            'approved_at' => $adjustment->approvedAt,
        ];
    }

    private function toDomain(LedgerAdjustmentRecord $record): LedgerAdjustment
    {
        return new LedgerAdjustment(
            id: $record->id,
            projectId: $record->project_id,
            sourceKind: AccountKind::fromString($record->source_kind),
            sourceReference: $record->source_reference,
            destinationKind: AccountKind::fromString($record->destination_kind),
            destinationReference: $record->destination_reference,
            amountMinor: $record->amount_minor,
            currency: $record->currency,
            reason: $record->reason,
            status: LedgerAdjustmentStatus::from($record->status),
            proposedBy: $record->proposed_by,
            approvedBy: $record->approved_by,
            ledgerTransactionId: $record->ledger_transaction_id,
            reversesTransactionId: $record->reverses_transaction_id,
            createdAt: DateTimeImmutable::createFromInterface($record->created_at),
            approvedAt: $record->approved_at === null ? null : DateTimeImmutable::createFromInterface($record->approved_at),
        );
    }
}
