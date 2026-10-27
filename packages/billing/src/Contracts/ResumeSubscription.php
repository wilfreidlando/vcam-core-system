<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0072 §6 — the operator's gesture: the contract becomes `active`
 * again and the current period is extended by the pause duration
 * (ADR-0072 §2).
 */
interface ResumeSubscription
{
    /**
     * @throws RuntimeException if the contract cannot be resumed — an
     *                          unknown contract, a contract that is not
     *                          paused. The concrete exception type lives
     *                          in the Domain layer; Contracts must not
     *                          reference it (G3c).
     */
    public function resume(ResumeSubscriptionCommand $command): SubscriptionView;
}
