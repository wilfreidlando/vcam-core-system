<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Core\Billing\Domain\Model\InvoiceLine;
use Core\Billing\Domain\Model\InvoiceLineKind;
use Core\Billing\Domain\Port\InvoiceLineRepository;
use DateTimeImmutable;

/**
 * ADR-0073 §1 — the lines of an invoice, on the disk.
 *
 * The lines of an issued invoice are frozen **by the database** (the
 * `invoice_line_immutable` trigger): this adapter does not have to
 * police it, and it cannot be tricked into writing over one.
 */
final class EloquentInvoiceLineRepository implements InvoiceLineRepository
{
    /**
     * @return list<InvoiceLine>
     */
    public function listByInvoice(string $invoiceId): array
    {
        // `toBase()` — `orderBy()` is forwarded by Eloquent's builder to
        // the query builder rather than declared on it, which trips a
        // Larastan false positive; `hydrate()` restores the model casts
        // afterwards (the same workaround as EloquentCustomerRepository).
        $rows = InvoiceLineRecord::query()->toBase()
            ->where('invoice_id', $invoiceId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)->all();

        $records = InvoiceLineRecord::hydrate($rows);

        $lines = [];

        foreach ($records as $record) {
            $lines[] = $this->toDomain($record);
        }

        return $lines;
    }

    public function save(InvoiceLine $line): void
    {
        InvoiceLineRecord::query()->create([
            'id' => $line->id,
            'invoice_id' => $line->invoiceId,
            'project_id' => $line->projectId,
            'kind' => $line->kind->value,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_amount_minor' => $line->unitAmountMinor,
            'amount_minor' => $line->amountMinor,
            'tax_rate_bps' => $line->taxRateBps,
            'tax_amount_minor' => $line->taxAmountMinor,
            'period_start' => $line->periodStart,
            'period_end' => $line->periodEnd,
            'created_at' => $line->createdAt,
        ]);
    }

    private function toDomain(InvoiceLineRecord $record): InvoiceLine
    {
        return new InvoiceLine(
            id: $record->id,
            invoiceId: $record->invoice_id,
            projectId: $record->project_id,
            kind: InvoiceLineKind::from($record->kind),
            description: $record->description,
            quantity: $record->quantity,
            unitAmountMinor: $record->unit_amount_minor,
            amountMinor: $record->amount_minor,
            taxRateBps: $record->tax_rate_bps,
            taxAmountMinor: $record->tax_amount_minor,
            periodStart: $record->period_start === null
                ? null
                : DateTimeImmutable::createFromInterface($record->period_start),
            periodEnd: $record->period_end === null
                ? null
                : DateTimeImmutable::createFromInterface($record->period_end),
            createdAt: DateTimeImmutable::createFromInterface($record->created_at),
        );
    }
}
