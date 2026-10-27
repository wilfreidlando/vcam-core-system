<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0070 §2 — `GET /v1/customers?cursor=&limit=`, the cursor-paginated
 * listing of a project's customers.
 *
 * The scope is a **required** parameter (ADR-0069 §2): the listing never
 * crosses the projects the console account may see.
 */
interface ListCustomers
{
    public function list(ListCustomersCommand $command, AccessScope $scope): CustomerPage;
}
