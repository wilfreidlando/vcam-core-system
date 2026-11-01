<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0072 §7 — `POST /v1/subscriptions/{id}/cancel`.
 *
 * `canceled` and `expired` are final states (ADR-0072 §2): a cancelled
 * contract never comes back — a new one is signed instead.
 */
interface CancelSubscription
{
    /**
     * @throws RuntimeException if the contract cannot be cancelled — an
     *                          unknown contract, a contract already
     *                          cancelled or expired. The concrete
     *                          exception type lives in the Domain layer;
     *                          Contracts must not reference it (G3c).
     */
    public function cancel(CancelSubscriptionCommand $command): SubscriptionView;
}
