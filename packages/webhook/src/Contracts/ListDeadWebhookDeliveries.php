<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

/**
 * The read side of the DLQ (ADR-0044) — `admin` lists dead deliveries here,
 * then replays one through the existing `ReplayWebhookDelivery` port.
 */
interface ListDeadWebhookDeliveries
{
    public function list(ListDeadWebhookDeliveriesCommand $command): DeadWebhookDeliveryPage;
}
