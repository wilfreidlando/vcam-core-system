<?php

declare(strict_types=1);

namespace Core\Billing\Application\Port;

/**
 * Remembers which one-time steps were announced for a contract.
 */
interface SubscriptionNoticeLog
{
    /**
     * Records the notice and says whether this call was the first to do so.
     * Called in the transaction that publishes the event, so a rollback
     * forgets both.
     */
    public function firstTime(string $subscriptionId, string $notice): bool;
}
