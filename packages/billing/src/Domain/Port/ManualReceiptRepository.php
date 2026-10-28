<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Port;

use Core\Billing\Domain\Model\ManualReceipt;

/**
 * ADR-0073 §4 — the offline receipts of a project.
 */
interface ManualReceiptRepository
{
    public function save(ManualReceipt $receipt): void;

    public function findById(string $id): ?ManualReceipt;

    /**
     * The receipts of an invoice, the oldest first, page after page.
     *
     * @return array{items: list<ManualReceipt>, nextCursor: ?string}
     */
    public function listByInvoice(string $invoiceId, ?string $cursor, int $limit): array;

    /**
     * The receipts of a project, filtered by status, page after page.
     *
     * @return array{items: list<ManualReceipt>, nextCursor: ?string}
     */
    public function listByProject(string $projectId, ?string $status, ?string $cursor, int $limit): array;
}
