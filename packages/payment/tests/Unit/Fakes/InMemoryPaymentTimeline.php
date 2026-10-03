<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Payment\Application\Service\PaymentJourney;
use Core\Payment\Domain\Model\PaymentTimelineEntry;
use Core\Payment\Domain\Model\PaymentTimelineEntryType;
use Core\Payment\Domain\Port\PaymentTimeline;
use Core\Support\Observability\InMemoryExchangeJournal;

final class InMemoryPaymentTimeline implements PaymentTimeline
{
    /** @var list<PaymentTimelineEntry> */
    public array $entries = [];

    public static function journey(?self $timeline = null): PaymentJourney
    {
        return new PaymentJourney($timeline ?? new self, new SequentialIdentifier, new FixedClock, new InMemoryExchangeJournal);
    }

    public function record(PaymentTimelineEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function forPayment(string $paymentId): array
    {
        return array_values(array_filter($this->entries, static fn (PaymentTimelineEntry $e): bool => $e->paymentId === $paymentId));
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_map(static fn (PaymentTimelineEntry $e): string => $e->type->value, $this->entries);
    }

    public function has(PaymentTimelineEntryType $type): bool
    {
        return in_array($type->value, $this->types(), true);
    }
}
