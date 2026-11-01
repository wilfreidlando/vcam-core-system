<?php

declare(strict_types=1);

namespace Core\Billing\Application\Support;

use Core\Billing\Contracts\SubscriptionPeriodView;
use Core\Billing\Contracts\SubscriptionView;
use Core\Billing\Domain\Model\Subscription;
use Core\Billing\Domain\Model\SubscriptionPeriod;

/**
 * The mapping from the contract of the domain to the read models of
 * `Contracts/` (G3c — the domain models themselves never leave the
 * module).
 */
final readonly class SubscriptionViews
{
    public static function from(
        Subscription $subscription,
        string $customerRef,
        string $planCode,
        int $planVersion,
        ?SubscriptionPeriod $currentPeriod,
        bool $hasAccess,
    ): SubscriptionView {
        return new SubscriptionView(
            id: $subscription->id,
            projectId: $subscription->projectId,
            customerId: $subscription->customerId,
            customerRef: $customerRef,
            planId: $subscription->planId,
            planCode: $planCode,
            planVersionId: $subscription->planVersionId,
            planVersion: $planVersion,
            status: $subscription->status->value,
            priceMinor: $subscription->priceMinor,
            currency: $subscription->currency,
            intervalUnit: $subscription->interval->unit->value,
            intervalCount: $subscription->interval->count,
            anchorAt: Iso8601::of($subscription->anchorAt),
            currentPeriodId: $subscription->currentPeriodId,
            currentPeriodStart: Iso8601::ofNullable($currentPeriod?->startsAt),
            currentPeriodEnd: Iso8601::ofNullable($currentPeriod?->endsAt),
            trialEndsAt: Iso8601::ofNullable($subscription->trialEndsAt),
            cancelAtPeriodEnd: $subscription->cancelAtPeriodEnd,
            pendingPlanVersionId: $subscription->pendingPlanVersionId,
            pausedAt: Iso8601::ofNullable($subscription->pausedAt),
            canceledAt: Iso8601::ofNullable($subscription->canceledAt),
            endedAt: Iso8601::ofNullable($subscription->endedAt),
            hasAccess: $hasAccess,
            version: $subscription->version,
            createdAt: Iso8601::of($subscription->createdAt),
            updatedAt: Iso8601::of($subscription->updatedAt),
        );
    }

    public static function period(SubscriptionPeriod $period): SubscriptionPeriodView
    {
        return new SubscriptionPeriodView(
            id: $period->id,
            subscriptionId: $period->subscriptionId,
            projectId: $period->projectId,
            seq: $period->seq,
            kind: $period->kind->value,
            startsAt: Iso8601::of($period->startsAt),
            endsAt: Iso8601::of($period->endsAt),
            state: $period->state->value,
            invoiceId: $period->invoiceId,
            reason: $period->reason,
            paidAt: Iso8601::ofNullable($period->paidAt),
        );
    }
}
