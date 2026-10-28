<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Core\Billing\Domain\Model\ManualReceipt;
use Core\Billing\Domain\Model\ManualReceiptMethod;
use Core\Billing\Domain\Model\ManualReceiptStatus;
use Core\Billing\Domain\Port\ManualReceiptRepository;
use Core\Billing\Infrastructure\Persistence\Cursor;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0073 §4, ADR-0095 — the offline receipts, on the disk.
 *
 * ADR-0095 withdrew the four eyes: the constraint
 * `manual_receipts_four_eyes` no longer exists, so a receipt written by
 * hand in SQL is not refused for being self-approved. The repository still
 * never polices the four eyes — there are none to police; it writes what
 * the use case decided.
 */
final class EloquentManualReceiptRepository implements ManualReceiptRepository
{
    /**
     * ADR-0088 §2 — a listing never returns more than a hundred rows.
     */
    private const MAX_LIMIT = 100;

    public function save(ManualReceipt $receipt): void
    {
        // SAVEPOINT (nested transaction) — always runs inside the use
        // case's outer transaction (the same comment as
        // EloquentInvoiceRepository::save()).
        DB::transaction(function () use ($receipt): void {
            $attributes = $this->toAttributes($receipt);

            if (ManualReceiptRecord::query()->whereKey($receipt->id)->toBase()->exists()) {
                ManualReceiptRecord::query()->whereKey($receipt->id)->update($attributes);
            } else {
                ManualReceiptRecord::query()->create(
                    ['id' => $receipt->id, 'created_at' => $receipt->createdAt] + $attributes,
                );
            }
        });
    }

    public function findById(string $id): ?ManualReceipt
    {
        $record = ManualReceiptRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    /**
     * ADR-0088 §2 — the receipts of an invoice, page after page.
     *
     * @return array{items: list<ManualReceipt>, nextCursor: ?string}
     */
    public function listByInvoice(string $invoiceId, ?string $cursor, int $limit): array
    {
        return $this->page(
            ManualReceiptRecord::query()->toBase()->where('invoice_id', $invoiceId),
            $cursor,
            $limit,
        );
    }

    /**
     * ADR-0088 §2 — the receipts of a project, page after page.
     *
     * @return array{items: list<ManualReceipt>, nextCursor: ?string}
     */
    public function listByProject(string $projectId, ?string $status, ?string $cursor, int $limit): array
    {
        $base = ManualReceiptRecord::query()->toBase()->where('project_id', $projectId);

        if ($status !== null && $status !== '') {
            $base->where('status', $status);
        }

        return $this->page($base, $cursor, $limit);
    }

    /**
     * @return array{items: list<ManualReceipt>, nextCursor: ?string}
     */
    private function page(QueryBuilder $base, ?string $cursor, int $limit): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        if ($cursor !== null) {
            [$createdAt, $id] = Cursor::decode($cursor);

            $base->where(static function (QueryBuilder $q) use ($createdAt, $id): void {
                $q->where('created_at', '>', $createdAt)
                    ->orWhere(static function (QueryBuilder $q2) use ($createdAt, $id): void {
                        $q2->where('created_at', '=', $createdAt)->where('id', '>', $id);
                    });
            });
        }

        $rows = $base->orderBy('created_at')->orderBy('id')->limit($limit + 1)->get()
            ->map(static fn (object $row): array => (array) $row)->all();

        $records = ManualReceiptRecord::hydrate($rows);

        $hasMore = $records->count() > $limit;
        $records = $records->take($limit);

        $items = [];
        foreach ($records as $record) {
            $items[] = $this->toDomain($record);
        }

        $last = $records->last();

        return [
            'items' => $items,
            'nextCursor' => $hasMore && $last !== null
                ? Cursor::encode($last->created_at->format('Y-m-d H:i:s.uP'), $last->id)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(ManualReceipt $receipt): array
    {
        return [
            'project_id' => $receipt->projectId,
            'invoice_id' => $receipt->invoiceId,
            'method' => $receipt->method->value,
            'reference' => $receipt->reference,
            'amount_minor' => $receipt->amountMinor,
            'currency' => $receipt->currency,
            'received_at' => $receipt->receivedAt,
            'recorded_by' => $receipt->recordedBy,
            'approved_by' => $receipt->approvedBy,
            'approved_at' => $receipt->approvedAt,
            'status' => $receipt->status->value,
            'evidence_ref' => $receipt->evidenceRef,
            'note' => $receipt->note,
            'ledger_transaction_id' => $receipt->ledgerTransactionId,
            'updated_at' => $receipt->updatedAt,
        ];
    }

    private function at(mixed $moment): ?DateTimeImmutable
    {
        return $moment instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($moment)
            : null;
    }

    private function toDomain(ManualReceiptRecord $record): ManualReceipt
    {
        return new ManualReceipt(
            id: $record->id,
            projectId: $record->project_id,
            invoiceId: $record->invoice_id,
            method: ManualReceiptMethod::from($record->method),
            reference: $record->reference,
            amountMinor: $record->amount_minor,
            currency: $record->currency,
            receivedAt: DateTimeImmutable::createFromInterface($record->received_at),
            recordedBy: $record->recorded_by,
            createdAt: DateTimeImmutable::createFromInterface($record->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($record->updated_at),
            approvedBy: $record->approved_by,
            approvedAt: $this->at($record->approved_at),
            status: ManualReceiptStatus::from($record->status),
            evidenceRef: $record->evidence_ref,
            note: $record->note,
            ledgerTransactionId: $record->ledger_transaction_id,
        );
    }
}
