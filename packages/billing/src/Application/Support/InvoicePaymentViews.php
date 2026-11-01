<?php

declare(strict_types=1);

namespace Core\Billing\Application\Support;

use Core\Billing\Contracts\InvoicePaymentView;
use Core\Billing\Domain\Model\InvoicePayment;

/**
 * The mapping from the collection attempt of the domain to the read
 * model of `Contracts/` (G3c — the domain model itself never leaves the
 * module).
 *
 * The status leaves as its `->value`, never as the enum; the dates leave
 * as ISO-8601 strings, in UTC, whatever the timezone of the project is.
 */
final readonly class InvoicePaymentViews
{
    public static function from(InvoicePayment $payment): InvoicePaymentView
    {
        return new InvoicePaymentView(
            id: $payment->id,
            invoiceId: $payment->invoiceId,
            projectId: $payment->projectId,
            paymentId: $payment->paymentId,
            status: $payment->status->value,
            amountMinor: $payment->amountMinor,
            currency: $payment->currency,
            confirmedAmountMinor: $payment->confirmedAmountMinor,
            failureReason: $payment->failureReason,
            settledAt: Iso8601::ofNullable($payment->settledAt),
            createdAt: Iso8601::of($payment->createdAt),
        );
    }
}
