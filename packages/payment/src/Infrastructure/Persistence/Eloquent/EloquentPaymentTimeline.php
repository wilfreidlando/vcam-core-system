<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Persistence\Eloquent;

use Core\Payment\Domain\Model\PaymentTimelineEntry;
use Core\Payment\Domain\Model\PaymentTimelineEntryType;
use Core\Payment\Domain\Port\PaymentTimeline;
use Core\Support\Observability\TraceContext;

final class EloquentPaymentTimeline implements PaymentTimeline
{
    public function record(PaymentTimelineEntry $entry): void
    {
        PaymentTimelineEntryRecord::query()->create([
            'id' => $entry->id,
            'payment_id' => $entry->paymentId,
            'project_id' => $entry->projectId,
            'type' => $entry->type->value,
            'occurred_at' => $entry->occurredAt,
            'summary' => $entry->summary,
            'details' => $entry->details,
            // ADR-0059: the request/job that produced this step — links the
            // entry to its logs (Loki) and trace (Tempo).
            'correlation_id' => $entry->correlationId ?? TraceContext::correlationId(),
            'trace_id' => $entry->traceId ?? TraceContext::traceId(),
        ]);
    }

    public function forPayment(string $paymentId): array
    {
        $records = PaymentTimelineEntryRecord::query()
            ->where('payment_id', $paymentId)
            ->toBase()
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $models = PaymentTimelineEntryRecord::hydrate(array_map(static fn (mixed $row): array => (array) $row, $records->all()));

        return array_values($models->map(static fn (PaymentTimelineEntryRecord $record): PaymentTimelineEntry => new PaymentTimelineEntry(
            id: $record->id,
            paymentId: $record->payment_id,
            projectId: $record->project_id,
            type: PaymentTimelineEntryType::tryFrom($record->type) ?? PaymentTimelineEntryType::StatusChanged,
            occurredAt: $record->occurred_at,
            summary: $record->summary,
            details: $record->details,
            correlationId: $record->correlation_id,
            traceId: $record->trace_id,
        ))->all());
    }
}
