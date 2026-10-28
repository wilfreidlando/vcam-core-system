<?php

declare(strict_types=1);

namespace Core\Billing\Application\Port;

use Core\Billing\Domain\Model\Invoice;
use Core\Billing\Domain\Model\InvoicePayment;
use Core\Billing\Domain\Model\ManualReceipt;

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

    /**
     * The imputation of a collection onto the receivable: Débit
     * `collections` / Crédit `customer_receivable:<client>`
     * (ADR-0073 §3).
     *
     * The `payment` module has already booked Débit `clearing` / Crédit
     * `collections` (ADR-0076 §3): this is the second half of the
     * movement, the one that gives the money back to the customer it
     * belongs to.
     *
     * @param int $amountMinor the part of the payment that is due on
     *                         the invoice — the whole attempt, unless
     *                         the invoice was settled in the meantime
     */
    public function postAllocation(InvoicePayment $payment, Invoice $invoice, int $amountMinor): string;

    /**
     * The money that arrived and cannot be imputed: Débit `collections`
     * / Crédit `payment_exceptions` (ADR-0073 §2).
     *
     * A late payment on an invoice that is already settled is **not
     * lost**: it waits on the exceptions account, where an operator
     * settles it.
     */
    public function postOverpayment(InvoicePayment $payment, int $amountMinor): string;

    /**
     * The offline collection: Débit `manual_receipts:<moyen>` / Crédit
     * `customer_receivable:<client>` (ADR-0073 §3).
     */
    public function postManualReceipt(ManualReceipt $receipt, Invoice $invoice): string;
}
