<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §1 — the read model of a line of an invoice.
 *
 * `$kind` is the canonical `InvoiceLineKind` enum's `->value`
 * ('subscription', 'manual'), never the enum itself (G3c).
 *
 * The tax of the line is stored, not recomputed: it was rounded half-up
 * **on that line** at the issuance, and the document is frozen
 * (ADR-0094 §1).
 */
final readonly class InvoiceLineView
{
    public function __construct(
        public string $id,
        public string $invoiceId,
        public string $kind,
        public string $description,
        public int $quantity,
        public int $unitAmountMinor,
        public int $amountMinor,
        public int $taxRateBps,
        public int $taxAmountMinor,
        public ?string $periodStart,
        public ?string $periodEnd,
    ) {}
}
