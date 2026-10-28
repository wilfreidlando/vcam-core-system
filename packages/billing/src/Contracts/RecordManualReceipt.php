<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §4 — the recording of an offline collection.
 *
 * The only « activation manuelle » the Core allows: the receipt says
 * what arrived, how much and under which reference, and it is booked
 * against the customer's receivable.
 *
 * Above the project's threshold, the receipt waits for a second console
 * account (ADR-0094 §13).
 */
interface RecordManualReceipt
{
    public function record(RecordManualReceiptCommand $command): ManualReceiptView;
}
