<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

/**
 * The full delivery history, across every status — pending, delivered and
 * dead — not just the DLQ `ListDeadWebhookDeliveries` exposes. Diagnosing a
 * problem needs to see what was actually executed, not only the subset
 * that ended up failing (review finding: an operator could see the dead
 * queue but never confirm whether a given event was ever attempted at
 * all, or delivered successfully).
 */
interface ListWebhookDeliveries
{
    public function list(ListWebhookDeliveriesCommand $command): WebhookDeliveryPage;
}
