<?php

declare(strict_types=1);

namespace Core\Billing\Application\Support;

use Core\Billing\Domain\Model\Invoice;

/**
 * ADR-0073 §1 — the public payload of every event of an invoice.
 *
 * The payload carries **no personal data** (ADR-0070 §3, ADR-0083): the
 * customer is named by the reference the project knows it by, which is
 * the project's own opaque key, and the invoice by its id. A consumer
 * that needs the customer reads it through the API.
 */
final readonly class InvoiceEvents
{
    /**
     * @param string|null $reason the motive of the act — the void and
     *                            the write-off are audited acts
     * @return array<string, mixed>
     */
    public static function payload(Invoice $invoice, string $customerRef, ?string $reason = null): array
    {
        return [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->number,
            'customer_ref' => $customerRef,
            'status' => $invoice->status->value,
            'total_minor' => $invoice->totalMinor,
            'amount_paid_minor' => $invoice->amountPaidMinor,
            'currency' => $invoice->currency,
            'due_at' => Iso8601::ofNullable($invoice->dueAt),
            'version' => $invoice->version,
            ...($reason === null ? [] : ['reason' => $reason]),
        ];
    }
}
