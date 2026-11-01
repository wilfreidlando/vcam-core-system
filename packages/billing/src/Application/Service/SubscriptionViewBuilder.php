<?php

declare(strict_types=1);

namespace Core\Billing\Application\Service;

use Core\Billing\Application\Support\SubscriptionViews;
use Core\Billing\Contracts\SubscriptionView;
use Core\Billing\Domain\Model\Subscription;
use Core\Billing\Domain\Model\SubscriptionPeriod;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\PlanRepository;
use Core\Billing\Domain\Port\PlanVersionRepository;
use Core\Billing\Domain\Port\SubscriptionPeriodRepository;

/**
 * The read model of a contract needs three neighbours the contract does
 * not carry: the customer (its reference), the plan (its code) and the
 * version it was signed on (its rank and its grace).
 *
 * They are read here, once, so that every use case that returns a
 * `SubscriptionView` tells the same story — and so that the question
 * "does this customer have its rights?" is answered in a single place
 * (ADR-0072 §3): the contract grants them while it is `trialing` or
 * `active`, while it is `past_due` only if the plan version grants the
 * grace, and never while the customer is suspended.
 */
final readonly class SubscriptionViewBuilder
{
    public function __construct(
        private CustomerRepository $customers,
        private PlanRepository $plans,
        private PlanVersionRepository $versions,
        private SubscriptionPeriodRepository $periods,
    ) {}

    public function build(Subscription $subscription): SubscriptionView
    {
        $customer = $this->customers->findById($subscription->customerId);
        $plan = $this->plans->findById($subscription->planId);
        $version = $this->versions->findById($subscription->planVersionId);

        $currentPeriod = $subscription->currentPeriodId === null
            ? null
            : $this->periods->findById($subscription->currentPeriodId);

        return SubscriptionViews::from(
            $subscription,
            $customer->externalRef ?? '',
            $plan->code ?? '',
            $version->version ?? 0,
            $currentPeriod,
            $this->hasAccess($subscription, $customer?->hasAccess() ?? false, $version?->graceAccess),
        );
    }

    /**
     * The reference the project knows its customer by — the only name a
     * public event carries (ADR-0070 §3, ADR-0083).
     */
    public function customerRef(Subscription $subscription): string
    {
        return $this->customers->findById($subscription->customerId)->externalRef ?? '';
    }

    /**
     * The period the contract is running, if any.
     */
    public function currentPeriod(Subscription $subscription): ?SubscriptionPeriod
    {
        if ($subscription->currentPeriodId === null) {
            return null;
        }

        return $this->periods->findById($subscription->currentPeriodId);
    }

    /**
     * ADR-0072 §3 — the rights follow the state, and a suspended customer
     * has none.
     */
    private function hasAccess(Subscription $subscription, bool $customerHasAccess, ?bool $graceAccess): bool
    {
        if (! $customerHasAccess) {
            return false;
        }

        return $subscription->hasAccess($graceAccess ?? false);
    }
}
