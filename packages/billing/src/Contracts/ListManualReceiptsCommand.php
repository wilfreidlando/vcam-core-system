<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §4 — the offline receipts of a project.
 *
 * `invoiceId` narrows the listing to one invoice, `status` to one
 * state ('pending', 'approved' or 'rejected') — `null` lists every
 * receipt.
 */
final readonly class ListManualReceiptsCommand
{
    public function __construct(
        public string $projectId,
        public ?string $invoiceId = null,
        public ?string $status = null,
        public ?string $cursor = null,
        public int $limit = 50,
    ) {}
}
