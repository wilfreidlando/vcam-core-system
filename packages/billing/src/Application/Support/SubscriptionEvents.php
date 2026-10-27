<?php

declare(strict_types=1);

namespace Core\Billing\Application\Support;

use Core\Billing\Domain\Model\Subscription;
use Core\Billing\Domain\Model\SubscriptionPeriod;

/**
 * ADR-0072 §9 — the public payload of every event of a contract.
 *
 * The payload carries **no personal data** (ADR-0070 §3, ADR-0083): the
 * customer is named by the reference the project knows it by, which is the
 * project's own opaque key, and the contract by its id. A consumer that
 * needs the customer reads it through the API.
 */
final readonly class SubscriptionEvents
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(
        Subscription $subscription,
        string $customerRef,
        ?SubscriptionPeriod $currentPeriod,
    ): array {
        return [
            'subscription_id' => $subscription->id,
            'customer_ref' => $customerRef,
            'status' => $subscription->status->value,
            // A cancellation at the end of the period and an immediate
            // one publish the same event name (the list is fixed by the
            // technical specification); the flag tells the two apart.
            'cancel_at_period_end' => $subscription->cancelAtPeriodEnd,
            'current_period' => $currentPeriod === null ? null : [
                'starts_at' => Iso8601::of($currentPeriod->startsAt),
                'ends_at' => Iso8601::of($currentPeriod->endsAt),
            ],
            'version' => $subscription->version,
        ];
    }
}
