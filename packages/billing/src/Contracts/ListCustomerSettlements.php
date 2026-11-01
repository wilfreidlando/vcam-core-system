<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0073 §2, §4 — « what did this customer pay me, against which
 * invoice, how, and when? » — the settlements of a customer, page after
 * page.
 *
 * The scope is a **required** parameter (ADR-0069 §2): the listing
 * never crosses the projects the console account may see.
 */
interface ListCustomerSettlements
{
    public function list(ListCustomerSettlementsCommand $command, AccessScope $scope): CustomerSettlementPage;
}
