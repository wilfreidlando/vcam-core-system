<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0072 §7 — `GET /v1/subscriptions/{id}`: the state of a contract,
 * its current period and its next due date.
 *
 * The scope is a **required** parameter (ADR-0069 §2): a contract of
 * another project is not found — never told apart from one that does not
 * exist.
 */
interface GetSubscription
{
    public function get(GetSubscriptionCommand $command, AccessScope $scope): ?SubscriptionView;
}
