<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0094 §13 — the life cycle of an offline receipt.
 *
 * - `pending` — recorded, but above the project's threshold: it waits
 *   for a **second** console account;
 * - `approved` — it became a bookkeeping fact;
 * - `rejected` — the second account refused it.
 *
 * Below the threshold, a receipt is `approved` from the start: no
 * second account is needed for a small amount (ADR-0094 §4).
 */
enum ManualReceiptStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * ADR-0073 §4 — the four eyes: the author of a receipt can never
     * approve it.
     */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
