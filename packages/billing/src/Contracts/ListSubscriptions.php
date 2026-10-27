<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0072 §7 — `GET /v1/subscriptions?customer_ref=&status=&cursor=`: the
 * contracts of one project, page after page.
 *
 * The scope is a **required** parameter (ADR-0069 §2): the listing never
 * crosses the projects the console account may see.
 */
interface ListSubscriptions
{
    public function list(ListSubscriptionsCommand $command, AccessScope $scope): SubscriptionPage;
}
