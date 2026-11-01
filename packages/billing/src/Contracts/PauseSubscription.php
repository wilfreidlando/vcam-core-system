<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0072 §6 — the operator's gesture: the contract is paused, for a
 * reason the audit trail keeps.
 */
interface PauseSubscription
{
    /**
     * @throws RuntimeException if the contract cannot be paused — an
     *                          unknown contract, a state the machine does
     *                          not allow to pause (ADR-0072 §2). The
     *                          concrete exception type lives in the
     *                          Domain layer; Contracts must not reference
     *                          it (G3c).
     */
    public function pause(PauseSubscriptionCommand $command): SubscriptionView;
}
