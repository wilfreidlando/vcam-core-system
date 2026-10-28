<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0073 §4 — the offline receipts of a project, page after page.
 *
 * The scope is a **required** parameter (ADR-0069 §2): the listing
 * never crosses the projects the console account may see.
 */
interface ListManualReceipts
{
    public function list(ListManualReceiptsCommand $command, AccessScope $scope): ManualReceiptPage;
}
