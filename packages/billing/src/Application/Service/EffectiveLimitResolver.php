<?php

declare(strict_types=1);

namespace Core\Billing\Application\Service;

use Core\Billing\Domain\Model\EffectiveLimits;
use Core\Billing\Domain\Model\Subscription;
use Core\Billing\Domain\Port\SubscriptionSupplementRepository;

/**
 * ADR-0117 §3, amended by ADR-0121 — the effective limit of a metered
 * feature: the limit of the plan version **plus** the allowance of the
 * supplements.
 *
 * This class is the **single source of truth** for that sum. The metered
 * call (ADR-0074 §2) and the entitlements (ADR-0074 §4) both read it,
 * so the number a client is told and the number the quota is enforced
 * against can never drift apart.
 *
 * **A pack covers the period in which its money arrived** (ADR-0121).
 * The sum is not the lifetime of the contract: it is the period in
 * progress. A pack bought in November is fifty messages more in
 * November; in December the limit is the one of the plan again. The
 * allowance of a counter that never resets (ADR-0071 §3) is the one
 * exception: that counter spans the whole contract, so its allowance
 * does too.
 *
 * It is a service and not a method of the contract: the supplements are
 * not part of the aggregate of the subscription (ADR-0072 §1) — they are
 * purchases that hang on it.
 */
final readonly class EffectiveLimitResolver
{
    public function __construct(
        private SubscriptionSupplementRepository $supplements,
    ) {}

    /**
     * The allowances of a contract, by meter — the ones of the period in
     * progress.
     *
     * Only the `active` supplements count (ADR-0117 §2): a purchase that
     * is not paid for yet grants nothing. And only the ones that cover
     * the period in progress count (ADR-0121): a pack paid last month
     * does not raise this month's limit.
     */
    public function forSubscription(Subscription $subscription): EffectiveLimits
    {
        $period = $subscription->currentPeriodId;
        $inPeriod = [];
        $lifetime = [];

        foreach ($this->supplements->listActiveBySubscription($subscription->id) as $supplement) {
            $lifetime[$supplement->meter] = ($lifetime[$supplement->meter] ?? 0) + $supplement->quantity;

            // A contract without a period in progress grants nothing: there
            // is no window to grant an allowance in.
            if ($period === null || $supplement->periodId !== $period) {
                continue;
            }

            $inPeriod[$supplement->meter] = ($inPeriod[$supplement->meter] ?? 0) + $supplement->quantity;
        }

        return new EffectiveLimits($inPeriod, $lifetime);
    }
}
