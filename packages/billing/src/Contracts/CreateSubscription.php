<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0072 §7 — `POST /v1/subscriptions`: the contract a customer signs
 * with a plan.
 *
 * The contract is created `trialing` (it starts with a trial) or
 * `incomplete` — the first invoice is issued in the same transaction
 * (ADR-0072 §Conséquences).
 */
interface CreateSubscription
{
    /**
     * @throws RuntimeException if the contract cannot be created — an
     *                          unknown customer or plan, a plan that takes
     *                          no new subscription, a live contract
     *                          already signed by that customer for that
     *                          plan. The concrete exception type lives in
     *                          the Domain layer; Contracts must not
     *                          reference it (G3c).
     */
    public function create(CreateSubscriptionCommand $command): SubscriptionView;
}
