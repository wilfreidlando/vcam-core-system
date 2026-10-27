<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §1 — a line as the caller declares it.
 *
 * The tax is a **rate**, never a tax regime: the Core computes what it
 * is given (ADR-0073 §1). `null` means "the default rate of the
 * project" — the caller that knows nothing of the tax does not have to
 * know it.
 */
final readonly class InvoiceLineInput
{
    public function __construct(
        public string $description,
        public int $quantity = 1,
        public int $unitAmountMinor = 0,
        public ?int $taxRateBps = null,
    ) {}
}
