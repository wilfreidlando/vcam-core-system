<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0073 §1 — `POST /v1/invoices`: a **manual** invoice, created
 * `draft`.
 *
 * A draft invoice has no number: the number is attributed at the
 * issuance, under the lock of the sequence (ADR-0094 §5). Creating and
 * issuing are two acts, because they are two decisions — a draft can be
 * corrected, an issued invoice cannot (ADR-0073 §1).
 */
interface CreateInvoice
{
    /**
     * @throws RuntimeException if the write is refused — an unknown
     *                          customer, an empty invoice, a currency the
     *                          ledger does not support. The concrete
     *                          exception type lives in the Domain layer;
     *                          Contracts must not reference it (G3c).
     */
    public function create(CreateInvoiceCommand $command): InvoiceView;
}
