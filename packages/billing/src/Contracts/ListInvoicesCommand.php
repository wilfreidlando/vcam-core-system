<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §1 — `GET /v1/invoices?customer_ref=&status=&cursor=`.
 *
 * `status` is a canonical `InvoiceStatus` value ('draft', 'open',
 * 'paid', 'void', 'uncollectible') as a plain string — never the enum
 * itself (G3c) — and nullable: `null` lists every status.
 */
final readonly class ListInvoicesCommand
{
    public function __construct(
        public string $projectId,
        public ?string $customerRef = null,
        public ?string $status = null,
        public ?string $cursor = null,
        public int $limit = 50,
    ) {}
}
