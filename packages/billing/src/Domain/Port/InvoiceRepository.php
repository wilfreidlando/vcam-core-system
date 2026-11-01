<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Port;

use Core\Billing\Domain\Model\Invoice;

/**
 * ADR-0073 §1 — the persistence port of the invoices of a project.
 *
 * The invoice is a **document**: the adapter writes it with the
 * optimistic lock of ADR-0094 §3 (the expected version travels in the
 * `WHERE` of the update), because two concurrent allocations reading
 * `amount_paid_minor = 0` would overwrite each other and break the
 * receivables invariant.
 */
interface InvoiceRepository
{
    /**
     * The invoice of its id, whichever project owns it — the caller
     * carries the tenant check.
     */
    public function findById(string $id): ?Invoice;

    /**
     * The invoice of a period of a contract, if it has one — the
     * `invoices_one_per_period` index admits one live invoice per
     * period (ADR-0073 §1).
     */
    public function findByPeriodId(string $periodId): ?Invoice;

    /**
     * ADR-0088 §2 — the invoices of a project, page after page.
     *
     * @param string|null $status the canonical status to filter on, `null` for every status
     * @param string|null $customerId the customer the invoices are for, `null` for every customer
     * @return array{items: list<Invoice>, nextCursor: ?string}
     */
    public function listByProject(
        string $projectId,
        ?string $status,
        ?string $customerId,
        ?string $cursor,
        int $limit,
    ): array;

    /**
     * Persists an invoice, created or updated.
     */
    public function save(Invoice $invoice): void;

    /**
     * ADR-0094 §7 — the erasure of a customer empties the snapshot of
     * its **draft** invoices, unless the project chose to keep the
     * identity it froze on them.
     *
     * An issued invoice is a document: its snapshot is frozen by the
     * database and survives the erasure — the receivable subsists, the
     * ledger stays balanced (ADR-0083 §4).
     *
     * @return int the number of snapshots emptied
     */
    public function clearDraftSnapshots(string $projectId, string $customerId): int;
}
