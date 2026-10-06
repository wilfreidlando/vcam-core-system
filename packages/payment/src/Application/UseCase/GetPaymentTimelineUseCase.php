<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Contracts\GetPaymentTimeline;
use Core\Payment\Contracts\PaymentTimelineEntryView;
use Core\Payment\Domain\Model\PaymentTimelineEntry;
use Core\Payment\Domain\Port\PaymentTimeline;

final readonly class GetPaymentTimelineUseCase implements GetPaymentTimeline
{
    public function __construct(
        private PaymentTimeline $timeline,
    ) {}

    public function forPayment(string $paymentId): array
    {
        return array_map(
            static fn (PaymentTimelineEntry $entry): PaymentTimelineEntryView => new PaymentTimelineEntryView(
                id: $entry->id,
                type: $entry->type->value,
                occurredAt: $entry->occurredAt->format(DATE_RFC3339_EXTENDED),
                summary: $entry->summary,
                details: $entry->details,
                correlationId: $entry->correlationId,
                traceId: $entry->traceId,
            ),
            $this->timeline->forPayment($paymentId),
        );
    }
}
