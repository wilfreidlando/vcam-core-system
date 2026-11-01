<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0073 §1 — `POST /v1/invoices/{id}/issue`: the draft becomes a
 * document.
 *
 * The issuance does three things at once, in one transaction: the number
 * is attributed under the lock of the sequence, the document is frozen,
 * and the receivable is written to the ledger (Débit
 * `customer_receivable:<client>` / Crédit `revenue`, ADR-0073 §3).
 */
interface IssueInvoice
{
    /**
     * @throws RuntimeException if the write is refused — an unknown or
     *                          already-issued invoice. The concrete
     *                          exception type lives in the Domain layer;
     *                          Contracts must not reference it (G3c).
     */
    public function issue(IssueInvoiceCommand $command): InvoiceView;
}
