<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0076 §6 — the console's view of the operating corrections.
 *
 * The scope is required (ADR-0069 §2) on every method: a correction of
 * a project the account may not see is not listed, not counted, and not
 * found.
 */
interface ListLedgerAdjustments
{
    public function list(ListLedgerAdjustmentsCommand $command, AccessScope $scope): LedgerAdjustmentPage;

    public function find(string $adjustmentId, AccessScope $scope): ?LedgerAdjustmentView;
}
