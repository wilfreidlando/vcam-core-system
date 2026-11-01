<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Core\Billing\Domain\Exception\InvoiceVersionConflict;
use Core\Billing\Domain\Model\Invoice;
use Core\Billing\Domain\Model\InvoiceStatus;
use Core\Billing\Domain\Port\InvoiceRepository;
use Core\Billing\Infrastructure\Persistence\Cursor;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * ADR-0073 §1 — the invoices of a project, on the disk.
 *
 * The write carries the **optimistic lock** (ADR-0094 §3): the expected
 * version travels in the `WHERE` of the update. Two issuances racing on
 * the same invoice must not overwrite each other, and the receivables
 * invariant must stay true.
 */
final class EloquentInvoiceRepository implements InvoiceRepository
{
    /**
     * ADR-0088 §2 — a listing never returns more than a hundred rows.
     */
    private const MAX_LIMIT = 100;

    public function findById(string $id): ?Invoice
    {
        $record = InvoiceRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByPeriodId(string $periodId): ?Invoice
    {
        $record = InvoiceRecord::query()
            ->where('period_id', $periodId)
            ->where('status', '!=', InvoiceStatus::Void->value)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    /**
     * ADR-0088 §2 — the invoices of a project, page after page.
     *
     * The cursor is the `(created_at, id)` pair of the last row of the
     * previous page: the listing never skips nor repeats a row, whatever
     * is written while it is read.
     *
     * @return array{items: list<Invoice>, nextCursor: ?string}
     */
    public function listByProject(
        string $projectId,
        ?string $status,
        ?string $customerId,
        ?string $cursor,
        int $limit,
    ): array {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $base = InvoiceRecord::query()->toBase()->where('project_id', $projectId);

        if ($status !== null && $status !== '') {
            $base->where('status', $status);
        }

        if ($customerId !== null) {
            $base->where('customer_id', $customerId);
        }

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

        $records = InvoiceRecord::hydrate($rows);

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

    public function save(Invoice $invoice): void
    {
        // SAVEPOINT (nested transaction) — always runs inside the use
        // case's outer transaction. Without this, a caught violation
        // poisons the whole outer PostgreSQL transaction (the same
        // comment as EloquentCustomerRepository::save()).
        DB::transaction(function () use ($invoice): void {
            $attributes = $this->toAttributes($invoice);

            // `toBase()` — `exists()` is forwarded by Eloquent's builder
            // to the query builder rather than declared on it, which
            // trips a Larastan false positive.
            if (InvoiceRecord::query()->whereKey($invoice->id)->toBase()->exists()) {
                // ADR-0094 §3 — the optimistic lock is **in the write**:
                // the row carries the version it had before the change.
                $updated = InvoiceRecord::query()
                    ->whereKey($invoice->id)
                    ->where('version', $invoice->version - 1)
                    ->update($attributes);

                if ($updated === 0) {
                    $stored = InvoiceRecord::query()->find($invoice->id);
                    $currentVersion = $stored === null ? $invoice->version : $stored->version;

                    throw InvoiceVersionConflict::forInvoice(
                        $invoice->id,
                        $invoice->version - 1,
                        $currentVersion,
                    );
                }
            } else {
                InvoiceRecord::query()->create(
                    ['id' => $invoice->id, 'created_at' => $invoice->createdAt] + $attributes,
                );
            }
        });
    }

    /**
     * ADR-0094 §7 — the erasure of a customer empties the snapshot of
     * its **draft** invoices.
     *
     * An issued invoice is a document: its snapshot is frozen by the
     * database, and the trigger refuses the write anyway — which is
     * exactly the guarantee we want.
     */
    public function clearDraftSnapshots(string $projectId, string $customerId): int
    {
        return InvoiceRecord::query()
            ->where('project_id', $projectId)
            ->where('customer_id', $customerId)
            ->where('status', InvoiceStatus::Draft->value)
            ->update(['customer_snapshot' => (string) json_encode(new stdClass)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(Invoice $invoice): array
    {
        return [
            'project_id' => $invoice->projectId,
            'customer_id' => $invoice->customerId,
            'subscription_id' => $invoice->subscriptionId,
            'period_id' => $invoice->periodId,
            'number' => $invoice->number,
            'status' => $invoice->status->value,
            'currency' => $invoice->currency,
            'subtotal_minor' => $invoice->subtotalMinor,
            'tax_minor' => $invoice->taxMinor,
            'total_minor' => $invoice->totalMinor,
            'amount_paid_minor' => $invoice->amountPaidMinor,
            'customer_snapshot' => $this->snapshot($invoice->customerSnapshot),
            'due_at' => $invoice->dueAt,
            'issued_at' => $invoice->issuedAt,
            'paid_at' => $invoice->paidAt,
            'voided_at' => $invoice->voidedAt,
            'void_reason' => $invoice->voidReason,
            'lost_at' => $invoice->lostAt,
            'ledger_transaction_id' => $invoice->ledgerTransactionId,
            'version' => $invoice->version,
            'updated_at' => $invoice->updatedAt,
        ];
    }

    /**
     * The snapshot is a **map** — and the column refuses anything else.
     * An empty PHP array would be stored as a JSON array: it is written
     * as an empty object instead.
     *
     * @param array<string, mixed> $snapshot
     */
    private function snapshot(array $snapshot): string
    {
        return (string) json_encode($snapshot === [] ? new stdClass : $snapshot);
    }

    /**
     * The snapshot as a map — the column holds a JSON **object**.
     *
     * @return array<string, mixed>
     */
    private function snapshotOf(string $json): array
    {
        $decoded = json_decode($json, true);
        /** @var array<string, mixed> $snapshot */
        $snapshot = is_array($decoded) ? $decoded : [];

        return $snapshot;
    }

    private function at(mixed $moment): ?DateTimeImmutable
    {
        return $moment instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($moment)
            : null;
    }

    private function toDomain(InvoiceRecord $record): Invoice
    {
        return new Invoice(
            id: $record->id,
            projectId: $record->project_id,
            customerId: $record->customer_id,
            subscriptionId: $record->subscription_id,
            periodId: $record->period_id,
            number: $record->number,
            status: InvoiceStatus::from($record->status),
            currency: $record->currency,
            subtotalMinor: $record->subtotal_minor,
            taxMinor: $record->tax_minor,
            totalMinor: $record->total_minor,
            amountPaidMinor: $record->amount_paid_minor,
            customerSnapshot: $this->snapshotOf($record->customer_snapshot),
            dueAt: $this->at($record->due_at),
            issuedAt: $this->at($record->issued_at),
            paidAt: $this->at($record->paid_at),
            voidedAt: $this->at($record->voided_at),
            voidReason: $record->void_reason,
            lostAt: $this->at($record->lost_at),
            ledgerTransactionId: $record->ledger_transaction_id,
            version: $record->version,
            createdAt: DateTimeImmutable::createFromInterface($record->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($record->updated_at),
        );
    }
}
