<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0073 §1 — `GET /v1/invoices/{id}`: the document, its lines and
 * what has been paid of it.
 *
 * The scope is a **required** parameter (ADR-0069 §2): an invoice of
 * another project is not found — never told apart from one that does not
 * exist.
 */
interface GetInvoice
{
    public function get(GetInvoiceCommand $command, AccessScope $scope): ?InvoiceView;
}
