<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0117 §2 — the supplements of a contract: what was bought on top
 * of its plan, and what is still waiting for its payment.
 *
 * The scope is a **required** parameter (ADR-0069 §2): the listing
 * never crosses the projects the caller may see.
 */
interface ListSubscriptionSupplements
{
    /**
     * @return list<SubscriptionSupplementView>
     */
    public function list(ListSubscriptionSupplementsCommand $command, AccessScope $scope): array;
}
