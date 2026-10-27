<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0073 §3 — the invoice becomes a bad debt.
 *
 * The receivable leaves the customer and lands on `bad_debt` (Débit
 * `bad_debt` / Crédit `customer_receivable:<client>`). The customer
 * still owes nothing: the document says the debt was written off, and
 * says so in the ledger.
 */
interface MarkInvoiceUncollectible
{
    /**
     * @throws RuntimeException if the write is refused — an unknown
     *                          invoice, or one that is not open. The
     *                          concrete exception type lives in the
     *                          Domain layer; Contracts must not reference
     *                          it (G3c).
     */
    public function markUncollectible(MarkInvoiceUncollectibleCommand $command): InvoiceView;
}
