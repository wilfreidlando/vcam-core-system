<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

/**
 * Moves a delivery to `dead` (DLQ) when its delivery job itself failed for
 * good — an exception the job could not recover from, or its job-level
 * tries exhausted — instead of leaving it `pending` forever (audit
 * 2026-10-03 §4.3). A no-op on a delivery already terminal.
 */
interface FailWebhookDelivery
{
    public function fail(string $deliveryId, string $reason): void;
}
