<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Port;

use Core\Billing\Domain\Model\InvoiceLine;

/**
 * ADR-0073 §1 — the persistence port of the lines of an invoice.
 *
 * The lines of an issued invoice are frozen **by the database** (the
 * `invoice_line_immutable` trigger) : this adapter does not have to
 * police it, and it cannot be tricked into writing over one.
 */
interface InvoiceLineRepository
{
    /**
     * The lines of an invoice, in the order they were written.
     *
     * @return list<InvoiceLine>
     */
    public function listByInvoice(string $invoiceId): array;

    /**
     * Persists a line of an invoice.
     */
    public function save(InvoiceLine $line): void;
}
