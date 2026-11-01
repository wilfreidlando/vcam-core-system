<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Core\Billing\Domain\Model\InvoicePayment;
use Core\Billing\Domain\Model\InvoicePaymentStatus;
use Core\Billing\Domain\Port\InvoicePaymentRepository;
use Core\Billing\Infrastructure\Persistence\Cursor;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0073 §2 — the collection attempts, on the disk.
 *
 * The transitions are **conditional**: an `UPDATE … WHERE status =
 * 'pending'` that returns the number of rows it moved. A replay of the
 * same status change moves nothing and says so (ADR-0073 §2).
 */
final class EloquentInvoicePaymentRepository implements InvoicePaymentRepository
{
    /**
     * ADR-0088 §2 — a listing never returns more than a hundred rows.
     */
    private const MAX_LIMIT = 100;

    public function save(InvoicePayment $payment): void
    {
        // SAVEPOINT (nested transaction) — always runs inside the use
        // case's outer transaction (the same comment as
        // EloquentInvoiceRepository::save()).
        DB::transaction(function () use ($payment): void {
            $attributes = $this->toAttributes($payment);

            if (InvoicePaymentRecord::query()->whereKey($payment->id)->toBase()->exists()) {
                InvoicePaymentRecord::query()->whereKey($payment->id)->update($attributes);
            } else {
                InvoicePaymentRecord::query()->create(
                    ['id' => $payment->id, 'created_at' => $payment->createdAt] + $attributes,
                );
            }
        });
    }

    public function findById(string $id): ?InvoicePayment
    {
        $record = InvoicePaymentRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByPaymentId(string $paymentId): ?InvoicePayment
    {
        $record = InvoicePaymentRecord::query()
            ->where('payment_id', $paymentId)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findPendingByInvoice(string $invoiceId): ?InvoicePayment
    {
        $record = InvoicePaymentRecord::query()
            ->where('invoice_id', $invoiceId)
            ->where('status', InvoicePaymentStatus::Pending->value)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function markSucceeded(
        string $id,
        string $ledgerTransactionId,
        DateTimeImmutable $at,
        ?int $confirmedAmountMinor,
    ): bool {
        return InvoicePaymentRecord::query()
            ->whereKey($id)
            ->where('status', InvoicePaymentStatus::Pending->value)
            ->update([
                'status' => InvoicePaymentStatus::Succeeded->value,
                'settled_at' => $at,
                'ledger_transaction_id' => $ledgerTransactionId,
                'confirmed_amount_minor' => $confirmedAmountMinor,
                'updated_at' => $at,
            ]) === 1;
    }

    public function markFailed(string $id, string $reason, DateTimeImmutable $at): bool
    {
        return InvoicePaymentRecord::query()
            ->whereKey($id)
            ->where('status', InvoicePaymentStatus::Pending->value)
            ->update([
                'status' => InvoicePaymentStatus::Failed->value,
                'failure_reason' => $reason,
                'updated_at' => $at,
            ]) === 1;
    }

    public function markCanceled(string $id, string $reason, DateTimeImmutable $at): bool
    {
        return InvoicePaymentRecord::query()
            ->whereKey($id)
            ->where('status', InvoicePaymentStatus::Pending->value)
            ->update([
                'status' => InvoicePaymentStatus::Canceled->value,
                'failure_reason' => $reason,
                'updated_at' => $at,
            ]) === 1;
    }

    /**
     * ADR-0088 §2 — the attempts of an invoice, page after page. The
     * cursor is the `(created_at, id)` pair of the last row of the
     * previous page.
     *
     * @return array{items: list<InvoicePayment>, nextCursor: ?string}
     */
    public function listByInvoice(string $invoiceId, ?string $cursor, int $limit): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $base = InvoicePaymentRecord::query()->toBase()->where('invoice_id', $invoiceId);

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

        $records = InvoicePaymentRecord::hydrate($rows);

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
    private function toAttributes(InvoicePayment $payment): array
    {
        return [
            'invoice_id' => $payment->invoiceId,
            'project_id' => $payment->projectId,
            'payment_id' => $payment->paymentId,
            'amount_minor' => $payment->amountMinor,
            'currency' => $payment->currency,
            'status' => $payment->status->value,
            'confirmed_amount_minor' => $payment->confirmedAmountMinor,
            'failure_reason' => $payment->failureReason,
            'settled_at' => $payment->settledAt,
            'ledger_transaction_id' => $payment->ledgerTransactionId,
            'updated_at' => $payment->updatedAt,
        ];
    }

    private function at(mixed $moment): ?DateTimeImmutable
    {
        return $moment instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($moment)
            : null;
    }

    private function toDomain(InvoicePaymentRecord $record): InvoicePayment
    {
        return new InvoicePayment(
            id: $record->id,
            invoiceId: $record->invoice_id,
            projectId: $record->project_id,
            paymentId: $record->payment_id,
            amountMinor: $record->amount_minor,
            currency: $record->currency,
            status: InvoicePaymentStatus::from($record->status),
            confirmedAmountMinor: $record->confirmed_amount_minor,
            failureReason: $record->failure_reason,
            settledAt: $this->at($record->settled_at),
            ledgerTransactionId: $record->ledger_transaction_id,
            createdAt: DateTimeImmutable::createFromInterface($record->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($record->updated_at),
        );
    }
}
