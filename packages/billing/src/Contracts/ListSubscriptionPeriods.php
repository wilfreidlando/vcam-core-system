<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0072 §1 — the periods of one contract.
 *
 * The periods of a contract never overlap (the exclusion constraint,
 * ADR-0072 §1) and there is one per renewal: they are read whole, not
 * paginated.
 *
 * The scope is a **required** parameter (ADR-0069 §2).
 */
interface ListSubscriptionPeriods
{
    public function list(ListSubscriptionPeriodsCommand $command, AccessScope $scope): SubscriptionPeriodPage;
}
