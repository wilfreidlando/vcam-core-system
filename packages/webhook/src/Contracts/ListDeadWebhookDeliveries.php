<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

use Core\Support\Access\AccessScope;

/**
 * The read side of the DLQ (ADR-0044) — `admin` lists dead deliveries here,
 * then replays one through the existing `ReplayWebhookDelivery` port.
 */
interface ListDeadWebhookDeliveries
{
    /**
     * ADR-0069 §2 — the scope is a REQUIRED parameter: the dead queue of a
     * project the console account may not see is not readable.
     */
    public function list(ListDeadWebhookDeliveriesCommand $command, AccessScope $scope): DeadWebhookDeliveryPage;
}
