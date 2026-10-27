<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Port;

use Core\Billing\Domain\Model\SubscriptionPeriod;

/**
 * ADR-0072 §1 — the persistence port of the periods of a contract.
 *
 * The periods of a contract never overlap (the exclusion constraint on
 * `subscription_periods`) and their ranks are unique and gapless
 * (`subscription_periods_seq_unique`).
 */
interface SubscriptionPeriodRepository
{
    /**
     * The period of its id, whichever project owns it — the caller
     * carries the tenant check.
     */
    public function findById(string $id): ?SubscriptionPeriod;

    /**
     * The period of a contract, by its rank.
     */
    public function findBySeq(string $subscriptionId, int $seq): ?SubscriptionPeriod;

    /**
     * The period the contract is running now — one at most
     * (`subscription_periods_one_current`).
     */
    public function findCurrent(string $subscriptionId): ?SubscriptionPeriod;

    /**
     * The period the contract is about to run: created before its
     * deadline (ADR-0072 §4), settled by a payment made in advance.
     */
    public function findUpcoming(string $subscriptionId): ?SubscriptionPeriod;

    /**
     * Every period of a contract, oldest first.
     *
     * @return list<SubscriptionPeriod>
     */
    public function listBySubscription(string $subscriptionId): array;

    /**
     * Persists a period, created or updated.
     */
    public function save(SubscriptionPeriod $period): void;
}
