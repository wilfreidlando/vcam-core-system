<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\Iso8601;
use Core\Billing\Contracts\EntitlementsView;
use Core\Billing\Contracts\EntitlementView;
use Core\Billing\Contracts\GetEntitlements;
use Core\Billing\Contracts\GetEntitlementsCommand;
use Core\Billing\Domain\Model\FeatureReset;
use Core\Billing\Domain\Model\FeatureType;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\PlanRepository;
use Core\Billing\Domain\Port\PlanVersionRepository;
use Core\Billing\Domain\Port\SubscriptionPeriodRepository;
use Core\Billing\Domain\Port\SubscriptionRepository;
use Core\Billing\Domain\Port\UsageStore;
use Core\Support\Access\AccessScope;

/**
 * ADR-0074 §4 — `GET /v1/customers/{ref}/entitlements`: "am I paid, what
 * may I do, how much is left".
 *
 * The answer is derived from the same rules the metered call applies
 * (ADR-0072 §3): the rights follow the state of the contract and the
 * customer. A customer without any granting contract is `active: false`
 * with no entitlement.
 */
final readonly class GetEntitlementsUseCase implements GetEntitlements
{
    public function __construct(
        private CustomerRepository $customers,
        private SubscriptionRepository $subscriptions,
        private SubscriptionPeriodRepository $periods,
        private PlanRepository $plans,
        private PlanVersionRepository $versions,
        private UsageStore $store,
    ) {}

    public function get(GetEntitlementsCommand $command, AccessScope $scope): ?EntitlementsView
    {
        if (! $scope->allowsProject($command->projectId)) {
            return null;
        }

        $customer = $this->customers->findByExternalRef($command->projectId, $command->customerRef);

        if ($customer === null || $customer->isErased()) {
            return null;
        }

        if ($customer->hasAccess()) {
            foreach ($this->subscriptions->listLiveByCustomer($customer->id) as $subscription) {
                $version = $this->versions->findById($subscription->planVersionId);

                if ($version === null || ! $subscription->hasAccess($version->graceAccess)) {
                    continue;
                }

                $period = $subscription->currentPeriodId === null
                    ? null
                    : $this->periods->findById($subscription->currentPeriodId);

                $entitlements = [];

                foreach ($version->features->all() as $key => $feature) {
                    if ($feature->type === FeatureType::Boolean) {
                        $entitlements[] = new EntitlementView($key, 'boolean', $feature->enabled, null, null, null, null, null, null, null, null);

                        continue;
                    }

                    $windowStart = $feature->reset === FeatureReset::Period ? $period?->startsAt : null;
                    $windowEnd = $feature->reset === FeatureReset::Period ? $period?->endsAt : null;
                    $counter = $this->store->counter($command->projectId, $customer->id, (string) $feature->meter, $windowStart);
                    $used = $counter->used ?? 0;

                    $entitlements[] = new EntitlementView(
                        $key,
                        'limit',
                        null,
                        $feature->meter,
                        $feature->limit,
                        $used,
                        $feature->limit === null ? null : max(0, $feature->limit - $used),
                        $feature->overage?->value,
                        $feature->thresholdBps,
                        $windowStart === null ? null : Iso8601::of($windowStart),
                        $windowEnd === null ? null : Iso8601::of($windowEnd),
                    );
                }

                return new EntitlementsView(
                    $customer->externalRef,
                    true,
                    $this->plans->findById($subscription->planId)->code ?? null,
                    $subscription->id,
                    $entitlements,
                );
            }
        }

        return new EntitlementsView($customer->externalRef, false, null, null, []);
    }
}
