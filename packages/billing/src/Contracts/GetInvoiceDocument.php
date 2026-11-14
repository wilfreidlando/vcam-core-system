<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0073 §5, ADR-0125 §1 — the printable invoice, with its header.
 *
 * The **same** document for the three surfaces that offer it: the public
 * API, the operator console and the customer portal. They consume this
 * contract, so they cannot diverge (ADR-0125 §1).
 *
 * The document is rendered from the **frozen lines** of the invoice: what
 * is printed is what was issued, never what the catalogue says today
 * (ADR-0073 §5).
 *
 * The scope is a **required** parameter (ADR-0069 §2, ADR-0125 §7): the
 * console is a caller of this port, and a port the console calls takes
 * the scope. An invoice of another project is **not found** — never told
 * apart from one that does not exist.
 */
interface GetInvoiceDocument
{
    /**
     * The document of an invoice, or `null` when the invoice is not
     * found in the scope.
     */
    public function document(GetInvoiceDocumentCommand $command, AccessScope $scope): ?string;
}
