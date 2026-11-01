<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §4, ADR-0094 §13, ADR-0095 — the validation of an offline
 * collection.
 *
 * ADR-0095 withdrew the four eyes ADR-0073 §4 prescribed: **one**
 * console account is enough to validate a receipt, the author
 * included. What remains is the deliberate, audited act.
 */
interface ApproveManualReceipt
{
    /**
     * @param ApproveManualReceiptCommand $command `approved = false` means the second
     *                                             account **refuses** the receipt
     */
    public function approve(ApproveManualReceiptCommand $command): ManualReceiptView;
}
