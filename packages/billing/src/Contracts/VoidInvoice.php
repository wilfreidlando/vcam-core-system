<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0073 §3 — `POST /v1/invoices/{id}/void`: the invoice is
 * cancelled before any payment.
 *
 * The issuance is counter-passed, the receivable leaves the ledger, and
 * the document keeps its number: a cancelled invoice stays in the series
 * of the project (ADR-0094 §5).
 */
interface VoidInvoice
{
    /**
     * @throws RuntimeException if the write is refused — an unknown
     *                          invoice, one already void, or one that
     *                          has already been paid. The concrete
     *                          exception type lives in the Domain layer;
     *                          Contracts must not reference it (G3c).
     */
    public function void(VoidInvoiceCommand $command): InvoiceView;
}
