<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0073 §2 — the attempts to collect an invoice, page after page.
 *
 * The scope is a **required** parameter (ADR-0069 §2): the listing
 * never crosses the projects the console account may see.
 */
interface ListInvoicePayments
{
    public function list(ListInvoicePaymentsCommand $command, AccessScope $scope): InvoicePaymentPage;
}
