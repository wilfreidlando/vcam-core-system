<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0073 §1 — `GET /v1/invoices?customer_ref=&status=&cursor=`: the
 * invoices of one project, page after page.
 *
 * The scope is a **required** parameter (ADR-0069 §2): the listing never
 * crosses the projects the console account may see.
 */
interface ListInvoices
{
    public function list(ListInvoicesCommand $command, AccessScope $scope): InvoicePage;
}
