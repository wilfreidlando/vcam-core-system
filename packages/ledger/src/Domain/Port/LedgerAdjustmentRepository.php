<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Port;

use Core\Ledger\Domain\Model\LedgerAdjustment;
use Core\Ledger\Domain\Model\LedgerAdjustmentStatus;

interface LedgerAdjustmentRepository
{
    public function create(LedgerAdjustment $adjustment): void;

    public function save(LedgerAdjustment $adjustment): void;

    public function find(string $id): ?LedgerAdjustment;

    /**
     * The correction, locked for update — so two people deciding on the
     * same correction at the same time cannot both book it.
     */
    public function findForUpdate(string $id): ?LedgerAdjustment;

    /**
     * @param list<string>|null $projectIds `null` = every project (a
     *                                      platform-wide reader only)
     * @return array{items: list<LedgerAdjustment>, total: int}
     */
    public function list(?array $projectIds, ?LedgerAdjustmentStatus $status, int $page, int $perPage): array;
}
