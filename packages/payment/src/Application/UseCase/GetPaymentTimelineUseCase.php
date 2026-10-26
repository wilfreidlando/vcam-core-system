<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Contracts\GetPaymentTimeline;
use Core\Payment\Contracts\PaymentTimelineEntryView;
use Core\Payment\Domain\Model\PaymentTimelineEntry;
use Core\Payment\Domain\Port\PaymentTimeline;
use Core\Support\Access\AccessScope;

final readonly class GetPaymentTimelineUseCase implements GetPaymentTimeline
{
    public function __construct(
        private PaymentTimeline $timeline,
    ) {}

    public function forPayment(string $paymentId, AccessScope $scope): array
    {
        $entries = $this->timeline->forPayment($paymentId);

        // ADR-0069 §2 — the journey of a payment outside the account's
        // scope is not readable. The entries carry their project id, so no
        // extra query is needed.
        $entries = array_values(array_filter(
            $entries,
            static fn (PaymentTimelineEntry $entry): bool => $scope->allowsProject($entry->projectId),
        ));

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
            $entries,
        );
    }
}
