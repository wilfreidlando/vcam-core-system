<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0094 §3 — the optimistic lock of an invoice.
 *
 * The caller sent the version it last read and the stored one has moved
 * on: the write is refused rather than silently overwriting the change
 * of somebody else (the discipline of the customer, ADR-0093 §8).
 */
final class InvoiceVersionConflict extends RuntimeException
{
    public static function forInvoice(string $invoiceId, int $expected, int $current): self
    {
        return new self(
            "The invoice \"{$invoiceId}\" has moved on: version {$expected} was expected, version {$current} is stored."
        );
    }
}
