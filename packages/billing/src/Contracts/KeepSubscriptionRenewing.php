<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * The counterpart of `CancelSubscription` at the end of the period: the
 * contract that was going to stop renews again. It publishes
 * `subscription.resumed` with `cancel_at_period_end = false`, so a consumer
 * that marked the contract as « ending » learns it is not.
 *
 * @throws RuntimeException the contract does not exist, or has ended
 */
interface KeepSubscriptionRenewing
{
    public function keep(KeepSubscriptionRenewingCommand $command): SubscriptionView;
}
