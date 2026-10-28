<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §2 — the attempts to collect one invoice.
 */
final readonly class ListInvoicePaymentsCommand
{
    public function __construct(
        public string $projectId,
        public string $invoiceId,
        public ?string $cursor = null,
        public int $limit = 50,
    ) {}
}
