<?php

declare(strict_types=1);

namespace Core\Ledger\Application\Support;

use Core\Ledger\Contracts\LedgerAdjustmentView;
use Core\Ledger\Domain\Model\LedgerAdjustment;
use DateTimeInterface;

/**
 * The read model of an operating correction, built from its domain
 * object — the same shape as `Core\Payment\Application\Support\MoneyExceptionViews`.
 */
final class LedgerAdjustmentViews
{
    public static function from(LedgerAdjustment $adjustment): LedgerAdjustmentView
    {
        return new LedgerAdjustmentView(
            id: $adjustment->id,
            projectId: $adjustment->projectId,
            sourceKind: (string) $adjustment->sourceKind,
            sourceReference: $adjustment->sourceReference,
            destinationKind: (string) $adjustment->destinationKind,
            destinationReference: $adjustment->destinationReference,
            amountMinor: $adjustment->amountMinor,
            currency: $adjustment->currency,
            reason: $adjustment->reason,
            status: $adjustment->status->value,
            proposedBy: $adjustment->proposedBy,
            approvedBy: $adjustment->approvedBy,
            ledgerTransactionId: $adjustment->ledgerTransactionId,
            reversesTransactionId: $adjustment->reversesTransactionId,
            createdAt: $adjustment->createdAt->format(DateTimeInterface::ATOM),
            approvedAt: $adjustment->approvedAt?->format(DateTimeInterface::ATOM),
        );
    }
}
