<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

use Core\Support\Access\AccessScope;

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
    /**
     * ADR-0069 §2 — the scope is a REQUIRED parameter: the history never
     * crosses the projects the console account may see.
     */
    public function list(ListWebhookDeliveriesCommand $command, AccessScope $scope): WebhookDeliveryPage;
}
