<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

/**
 * Audit 2026-10-03 §4.3 — finds `pending` deliveries whose queued job was
 * lost (queue flush, crash between row creation and dispatch) so they can
 * be dispatched again. Returns the delivery ids to dispatch.
 */
interface SweepStalledWebhookDeliveries
{
    /**
     * @return list<string>
     */
    public function sweep(int $overdueMinutes, int $limit): array;
}
