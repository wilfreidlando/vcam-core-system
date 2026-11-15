<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §5, ADR-0125 §1 — the invoice whose document is asked for.
 *
 * The project travels with it: the document carries the header of the
 * project that issued the invoice, and the invoice of another project is
 * not found (ADR-0069 §2).
 */
final readonly class GetInvoiceDocumentCommand
{
    public function __construct(
        public string $projectId,
        public string $invoiceId,
    ) {}
}
