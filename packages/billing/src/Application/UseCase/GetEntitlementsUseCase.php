<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Service\EffectiveLimitResolver;
use Core\Billing\Application\Support\Iso8601;
use Core\Billing\Contracts\EntitlementsView;
use Core\Billing\Contracts\EntitlementView;
use Core\Billing\Contracts\GetEntitlements;
use Core\Billing\Contracts\GetEntitlementsCommand;
use Core\Billing\Domain\Model\FeatureReset;
use Core\Billing\Domain\Model\FeatureType;
use Core\Billing\Domain\Port\BillingSettingsRepository;
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
        private EffectiveLimitResolver $limits,
        private BillingSettingsRepository $settings,
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

                // ADR-0117 §3 — the allowances of the contract, read from
                // the same resolver as the metered call: the number the
                // client is told and the number the quota is enforced
                // against can never drift apart.
                $limits = $this->limits->forSubscription($subscription);

                foreach ($version->features->all() as $key => $feature) {
                    if ($feature->type === FeatureType::Boolean) {
                        $entitlements[] = new EntitlementView($key, 'boolean', $feature->enabled, null, null, null, null, null, null, null, null);

                        continue;
                    }

                    $windowStart = $feature->reset === FeatureReset::Period ? $period?->startsAt : null;
                    $windowEnd = $feature->reset === FeatureReset::Period ? $period?->endsAt : null;
                    $counter = $this->store->counter($command->projectId, $customer->id, (string) $feature->meter, $windowStart);
                    $used = $counter->used ?? 0;

                    // ADR-0117 §3 — the effective limit: the limit of the
                    // plan version plus the allowance of the active
                    // supplements.
                    $limit = $limits->limitFor((string) $feature->meter, $feature->limit, $feature->reset);

                    $entitlements[] = new EntitlementView(
                        $key,
                        'limit',
                        null,
                        $feature->meter,
                        $limit,
                        $used,
                        $limit === null ? null : max(0, $limit - $used),
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
                    $this->avecCapacites($command->projectId, $entitlements),
                );
            }
        }

        return new EntitlementsView($customer->externalRef, false, null, null, []);
    }

    /**
     * The same rights, **under the capability's name**.
     *
     * A client application knows the capabilities it consumes — it sends
     * `notification.whatsapp`, it does not know that the plan calls the
     * meter `whatsapp_messages`. ADR-0074 §5 makes the link explicit:
     * the project declares which capability feeds which meter.
     *
     * So each declared capability that has a feature on its meter is
     * exposed a second time, under the capability's name. The numbers are
     * the same ones the quota is enforced against — read from the same
     * resolver — so the client and the Core can never disagree.
     *
     * @param list<EntitlementView> $entitlements
     * @return list<EntitlementView>
     */
    private function avecCapacites(string $projectId, array $entitlements): array
    {
        $settings = $this->settings->find($projectId);
        $parCapacite = [];

        foreach ($entitlements as $entitlement) {
            foreach ($settings->meterMappings as $capability => $meter) {
                if ($entitlement->meter !== $meter) {
                    continue;
                }

                $parCapacite[] = new EntitlementView(
                    $capability,
                    $entitlement->type,
                    $entitlement->enabled,
                    $entitlement->meter,
                    $entitlement->limit,
                    $entitlement->used,
                    $entitlement->remaining,
                    $entitlement->overage,
                    $entitlement->thresholdBps,
                    $entitlement->windowStart,
                    $entitlement->windowEnd,
                );
            }
        }

        return [...$entitlements, ...$parCapacite];
    }
}
