<?php

declare(strict_types=1);

namespace Core\Billing\Application\Port;

use Core\Billing\Contracts\InvoiceView;

/**
 * ADR-0073 §5 — the printable invoice.
 *
 * The rendering of the frozen document is an **adapter**: a use case asks
 * for a document, it does not build HTML (the layering rule of
 * `deptrac-layers.yaml` — the Application knows the Domain and the
 * Contracts, never an adapter).
 *
 * The document is rendered from the **frozen lines**: what is printed is
 * what was issued, never what the catalogue says today (ADR-0073
 * §Alternatives).
 */
interface InvoiceDocumentRenderer
{
    /**
     * @param array<string, mixed> $branding the free marks of the project
     *                                       (ADR-0094 §4)
     */
    public function render(InvoiceView $invoice, array $branding = []): string;
}
