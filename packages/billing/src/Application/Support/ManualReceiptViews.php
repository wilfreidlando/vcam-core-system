<?php

declare(strict_types=1);

namespace Core\Billing\Application\Support;

use Core\Billing\Contracts\ManualReceiptView;
use Core\Billing\Domain\Model\ManualReceipt;

/**
 * The mapping from the offline receipt of the domain to the read model
 * of `Contracts/` (G3c — the domain model itself never leaves the
 * module).
 *
 * The method and the status leave as their `->value`, never as the
 * enum; the dates leave as ISO-8601 strings, in UTC.
 */
final readonly class ManualReceiptViews
{
    public static function from(ManualReceipt $receipt): ManualReceiptView
    {
        return new ManualReceiptView(
            id: $receipt->id,
            projectId: $receipt->projectId,
            invoiceId: $receipt->invoiceId,
            method: $receipt->method->value,
            reference: $receipt->reference,
            amountMinor: $receipt->amountMinor,
            currency: $receipt->currency,
            receivedAt: Iso8601::of($receipt->receivedAt),
            recordedBy: $receipt->recordedBy,
            status: $receipt->status->value,
            approvedBy: $receipt->approvedBy,
            approvedAt: Iso8601::ofNullable($receipt->approvedAt),
            evidenceRef: $receipt->evidenceRef,
            note: $receipt->note,
            createdAt: Iso8601::of($receipt->createdAt),
        );
    }
}
