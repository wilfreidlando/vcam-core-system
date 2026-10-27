<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * The read model of a contract — never
 * `Core\Billing\Domain\Model\Subscription`, which stays internal (G3c).
 *
 * `$status` is a `SubscriptionStatus` value ('incomplete', 'trialing',
 * 'active', 'past_due', 'paused', 'canceled', 'expired') and
 * `$intervalUnit` 'day', 'week', 'month' or 'year', both as plain
 * strings: the enums stay in `Domain/` (G3c).
 *
 * The price, the currency and the interval are the ones the contract was
 * signed on: a later version of the same plan does not touch them
 * (ADR-0071 §Conséquences).
 *
 * `hasAccess` states whether the customer has its rights right now
 * (ADR-0072 §3): `trialing`, `active` or `past_due` with
 * `grace_access = true`, and never while the customer is `suspended`.
 */
final readonly class SubscriptionView
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $customerId,
        public string $customerRef,
        public string $planId,
        public string $planCode,
        public string $planVersionId,
        public int $planVersion,
        public string $status,
        public int $priceMinor,
        public string $currency,
        public string $intervalUnit,
        public int $intervalCount,
        public string $anchorAt,
        public ?string $currentPeriodId = null,
        public ?string $currentPeriodStart = null,
        public ?string $currentPeriodEnd = null,
        public ?string $trialEndsAt = null,
        public bool $cancelAtPeriodEnd = false,
        public ?string $pendingPlanVersionId = null,
        public ?string $pausedAt = null,
        public ?string $canceledAt = null,
        public ?string $endedAt = null,
        public bool $hasAccess = false,
        public int $version = 1,
        public string $createdAt = '',
        public string $updatedAt = '',
    ) {}
}
