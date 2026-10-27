<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0072 §5 — `POST /v1/subscriptions/{id}/change-plan`.
 *
 * At the end of the period (the default), the next period is created
 * with the new version; immediately, the current period is closed now and
 * a new one starts. No prorata in v1 (ADR-0072 §5).
 */
interface ChangeSubscriptionPlan
{
    /**
     * @throws RuntimeException if the change cannot be applied — an
     *                          unknown contract or plan, a contract
     *                          already cancelled or expired. The
     *                          concrete exception type lives in the
     *                          Domain layer; Contracts must not reference
     *                          it (G3c).
     */
    public function changePlan(ChangeSubscriptionPlanCommand $command): SubscriptionView;
}
