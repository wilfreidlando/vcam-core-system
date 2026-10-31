<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Core\Billing\Domain\Model\CustomerSettlement;
use Core\Billing\Domain\Model\CustomerSettlementKind;
use Core\Billing\Domain\Port\CustomerSettlementRepository;
use Core\Billing\Infrastructure\Persistence\Cursor;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * ADR-0073 §2, §4 — the settlements of a customer, on the disk.
 *
 * The two sources are read in **one** statement: a `UNION ALL` of the
 * online attempts and the offline receipts, each joined to the invoice
 * it was imputed on. The join is what carries the customer: a
 * settlement belongs to a customer through its invoice, never through
 * its own columns — which say nothing about it.
 *
 * The list is the newest first, and the cursor is the module's opaque
 * cursor on the `(occurred_at, id)` pair. The identifier of the row is
 * the tiebreaker, so a page never skips nor repeats a row — whatever is
 * written while it is read.
 */
final class EloquentCustomerSettlementRepository implements CustomerSettlementRepository
{
    /**
     * ADR-0088 §2 — a listing never returns more than a hundred rows.
     */
    private const MAX_LIMIT = 100;

    /**
     * The settlements of a customer of a project, the most recent first.
     *
     * @return array{items: list<CustomerSettlement>, nextCursor: ?string}
     */
    public function listByCustomer(
        string $projectId,
        string $customerId,
        ?string $cursor,
        int $limit,
    ): array {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $payments = $this->payments($projectId, $customerId, $cursor);
        $receipts = $this->receipts($projectId, $customerId, $cursor);

        // The order and the limit of the union are the ones of the **list**,
        // not of one of its sources: the chronology is what the operator
        // reads.
        $rows = $payments->unionAll($receipts)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->toDomain($row);
        }

        $last = $rows->last();

        return [
            'items' => $items,
            'nextCursor' => $hasMore && $last !== null
                ? Cursor::encode(
                    $this->occurredAtOf($last)->format('Y-m-d H:i:s.uP'),
                    $this->string($last->id),
                )
                : null,
        ];
    }

    /**
     * ADR-0073 §2 — the online attempts of the invoices of a customer.
     */
    private function payments(string $projectId, string $customerId, ?string $cursor): QueryBuilder
    {
        $query = DB::query()
            ->from('invoice_payments')
            ->join('invoices', 'invoices.id', '=', 'invoice_payments.invoice_id')
            ->where('invoice_payments.project_id', $projectId)
            ->where('invoices.project_id', $projectId)
            ->where('invoices.customer_id', $customerId)
            ->selectRaw(
                'invoice_payments.id as id, '
                ."'payment'::text as kind, "
                .'invoice_payments.invoice_id as invoice_id, '
                .'invoices.number as invoice_number, '
                ."'online'::text as method, "
                // The reference of a payment is the id of the `payment`
                // module's row: it is cast to text because the receipt's
                // own reference is a text, and a union needs one type.
                .'invoice_payments.payment_id::text as reference, '
                .'invoice_payments.amount_minor as amount_minor, '
                .'invoice_payments.currency as currency, '
                .'invoice_payments.status as status, '
                .'COALESCE(invoice_payments.settled_at, invoice_payments.created_at) as occurred_at'
            );

        return $this->before(
            $query,
            '(COALESCE(invoice_payments.settled_at, invoice_payments.created_at) < ?)'
            .' OR (COALESCE(invoice_payments.settled_at, invoice_payments.created_at) = ? AND invoice_payments.id < ?)',
            $cursor,
        );
    }

    /**
     * ADR-0073 §4 — the offline receipts of the invoices of a customer.
     */
    private function receipts(string $projectId, string $customerId, ?string $cursor): QueryBuilder
    {
        $query = DB::query()
            ->from('manual_receipts')
            ->join('invoices', 'invoices.id', '=', 'manual_receipts.invoice_id')
            ->where('manual_receipts.project_id', $projectId)
            ->where('invoices.project_id', $projectId)
            ->where('invoices.customer_id', $customerId)
            ->selectRaw(
                'manual_receipts.id as id, '
                ."'receipt'::text as kind, "
                .'manual_receipts.invoice_id as invoice_id, '
                .'invoices.number as invoice_number, '
                .'manual_receipts.method as method, '
                .'manual_receipts.reference as reference, '
                .'manual_receipts.amount_minor as amount_minor, '
                .'manual_receipts.currency as currency, '
                .'manual_receipts.status as status, '
                .'COALESCE(manual_receipts.approved_at, manual_receipts.received_at) as occurred_at'
            );

        return $this->before(
            $query,
            '(COALESCE(manual_receipts.approved_at, manual_receipts.received_at) < ?)'
            .' OR (COALESCE(manual_receipts.approved_at, manual_receipts.received_at) = ? AND manual_receipts.id < ?)',
            $cursor,
        );
    }

    /**
     * The rows that come **before** the cursor — the list being the newest
     * first, the page continues below the cursor.
     *
     * The filter is applied to each source *before* the union: a `WHERE`
     * written on the union itself would only filter its first source, and
     * the second one would answer with rows the page must not show.
     *
     * @param literal-string $sql
     */
    private function before(QueryBuilder $query, string $sql, ?string $cursor): QueryBuilder
    {
        if ($cursor === null) {
            return $query;
        }

        [$at, $identifier] = Cursor::decode($cursor);

        return $query->whereRaw($sql, [$at, $at, $identifier]);
    }

    private function toDomain(stdClass $row): CustomerSettlement
    {
        return new CustomerSettlement(
            id: $this->string($row->id),
            kind: CustomerSettlementKind::from($this->string($row->kind)),
            invoiceId: $this->string($row->invoice_id),
            invoiceNumber: $this->nullableString($row->invoice_number),
            method: $this->string($row->method),
            reference: $this->string($row->reference),
            amountMinor: $this->int($row->amount_minor),
            currency: $this->string($row->currency),
            status: $this->string($row->status),
            occurredAt: $this->occurredAtOf($row),
        );
    }

    /**
     * The instant the settlement happened — the column is a `timestamptz`,
     * the driver hands it over as the instant the database saw it.
     */
    private function occurredAtOf(stdClass $row): DateTimeImmutable
    {
        return new DateTimeImmutable($this->string($row->occurred_at));
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : $this->string($value);
    }
}
