<?php

declare(strict_types=1);

namespace Core\Billing\Application\Support;

use Core\Billing\Contracts\InvoiceLineView;
use Core\Billing\Contracts\InvoiceView;
use Core\Billing\Domain\Model\Invoice;
use Core\Billing\Domain\Model\InvoiceLine;

/**
 * The mapping from the invoice of the domain to the read model of
 * `Contracts/` (G3c — the domain model itself never leaves the module).
 *
 * The status leaves as its `->value`, never as the enum; the dates leave
 * as ISO-8601 strings, in UTC, whatever the timezone of the project is.
 */
final readonly class InvoiceViews
{
    /**
     * @param list<InvoiceLine> $lines
     */
    public static function from(Invoice $invoice, string $customerRef, array $lines): InvoiceView
    {
        return new InvoiceView(
            id: $invoice->id,
            projectId: $invoice->projectId,
            customerId: $invoice->customerId,
            customerRef: $customerRef,
            subscriptionId: $invoice->subscriptionId,
            periodId: $invoice->periodId,
            number: $invoice->number,
            status: $invoice->status->value,
            currency: $invoice->currency,
            subtotalMinor: $invoice->subtotalMinor,
            taxMinor: $invoice->taxMinor,
            totalMinor: $invoice->totalMinor,
            amountPaidMinor: $invoice->amountPaidMinor,
            customerSnapshot: $invoice->customerSnapshot,
            lines: array_map(self::line(...), $lines),
            dueAt: Iso8601::ofNullable($invoice->dueAt),
            issuedAt: Iso8601::ofNullable($invoice->issuedAt),
            paidAt: Iso8601::ofNullable($invoice->paidAt),
            voidedAt: Iso8601::ofNullable($invoice->voidedAt),
            voidReason: $invoice->voidReason,
            version: $invoice->version,
            createdAt: Iso8601::of($invoice->createdAt),
            updatedAt: Iso8601::of($invoice->updatedAt),
        );
    }

    public static function line(InvoiceLine $line): InvoiceLineView
    {
        return new InvoiceLineView(
            id: $line->id,
            invoiceId: $line->invoiceId,
            kind: $line->kind->value,
            description: $line->description,
            quantity: $line->quantity,
            unitAmountMinor: $line->unitAmountMinor,
            amountMinor: $line->amountMinor,
            taxRateBps: $line->taxRateBps,
            taxAmountMinor: $line->taxAmountMinor,
            periodStart: Iso8601::ofNullable($line->periodStart),
            periodEnd: Iso8601::ofNullable($line->periodEnd),
        );
    }
}
