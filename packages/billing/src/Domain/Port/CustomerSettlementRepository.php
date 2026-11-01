<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Port;

use Core\Billing\Domain\Model\CustomerSettlement;

/**
 * ADR-0073 §2, §4 — the persistence port of the settlements of a
 * customer.
 *
 * The settlement is the meeting of two tables (`invoice_payments` and
 * `manual_receipts`) with the invoices they were imputed on: the port
 * is what puts them in one chronology.
 */
interface CustomerSettlementRepository
{
    /**
     * The settlements of a customer of a project, the most recent
     * first, page after page.
     *
     * @param string $customerId the id of the Core's customer — never its reference
     * @return array{items: list<CustomerSettlement>, nextCursor: ?string}
     */
    public function listByCustomer(
        string $projectId,
        string $customerId,
        ?string $cursor,
        int $limit,
    ): array;
}
