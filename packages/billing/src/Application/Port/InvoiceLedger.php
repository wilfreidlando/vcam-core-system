<?php

declare(strict_types=1);

namespace Core\Billing\Application\Port;

use Core\Billing\Domain\Model\Invoice;

/**
 * ADR-0073 §3 — the writes of the invoice in the ledger.
 *
 * The billing module never writes to the ledger itself: it says **what**
 * happened, and the adapter says **how** it is booked (ADR-0076). The
 * accounts it mints are local to the module — the chart of accounts is
 * open, and `billing` adds `customer_receivable`, `revenue`,
 * `collections`, `manual_receipts` and `bad_debt` to it (ADR-0076 §4).
 */
interface InvoiceLedger
{
    /**
     * The issuance: Débit `customer_receivable:<client>` / Crédit
     * `revenue`.
     *
     * @return string the id of the transaction, kept by the invoice so
     *                that its cancellation can counter-pass it
     */
    public function postIssuance(Invoice $invoice): string;

    /**
     * The cancellation: the counter-passation of the issuance.
     */
    public function postVoid(Invoice $invoice): string;

    /**
     * The write-off: Débit `bad_debt` / Crédit
     * `customer_receivable:<client>`.
     */
    public function postUncollectible(Invoice $invoice): string;
}
