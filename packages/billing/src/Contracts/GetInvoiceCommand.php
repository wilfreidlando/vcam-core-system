<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §1 — the invoice to read, by its id.
 */
final readonly class GetInvoiceCommand
{
    public function __construct(
        public string $projectId,
        public string $invoiceId,
    ) {}
}
