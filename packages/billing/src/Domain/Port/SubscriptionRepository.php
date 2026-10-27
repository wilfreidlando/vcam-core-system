<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Port;

use Core\Billing\Domain\Exception\InvalidSubscriptionTransition;
use Core\Billing\Domain\Model\Subscription;

/**
 * ADR-0072 §1 — the persistence port of the contracts of a project.
 */
interface SubscriptionRepository
{
    /**
     * The contract of its id, whichever project owns it — the caller
     * carries the tenant check.
     */
    public function findById(string $id): ?Subscription;

    /**
     * The **live** contract of a customer for a plan — one at most
     * (`subscriptions_live_unique`).
     *
     * A contract is live while it is `incomplete`, `trialing`, `active`,
     * `past_due` or `paused`; `canceled` and `expired` are final
     * (ADR-0072 §2), so a new contract may then be signed on the same
     * plan.
     */
    public function findLiveByCustomerAndPlan(string $customerId, string $planId): ?Subscription;

    /**
     * Persists a contract, created or updated.
     *
     * **The optimistic lock (ADR-0072 §2).** The write is an
     * `UPDATE subscriptions SET … WHERE id = ? AND status = :expectedStatus
     * AND version = :expectedVersion`, where the expected status and
     * version are the ones the row carried **before** the transition.
     * Zero row updated means the state moved under our feet — the write is
     * refused with `InvalidSubscriptionTransition`, never applied blindly:
     * two transitions racing on the same contract can therefore never
     * overwrite each other, and the state machine of ADR-0072 §2 stays
     * the one authority on what a contract may become.
     *
     * The contract must have been read through this port and then moved
     * forward by its own domain methods (each of them bumps its
     * `version`): a contract whose version has not moved past the
     * persisted one is a stale copy, and is refused as well.
     *
     * @throws InvalidSubscriptionTransition the row moved under our feet (the optimistic lock), or the customer already holds a live contract for this plan
     */
    public function save(Subscription $subscription): void;
}
