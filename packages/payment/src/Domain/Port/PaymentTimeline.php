<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Port;

use Core\Payment\Domain\Model\PaymentTimelineEntry;

interface PaymentTimeline
{
    public function record(PaymentTimelineEntry $entry): void;

    /**
     * Oldest first.
     *
     * @return list<PaymentTimelineEntry>
     */
    public function forPayment(string $paymentId): array;
}
