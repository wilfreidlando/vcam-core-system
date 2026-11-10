<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0117 §2 — the life of a supplement.
 *
 * A supplement is **bought before it counts**: it is `pending` while the
 * invoice that carries it is not settled, `active` once that invoice is
 * paid, and `canceled` when that invoice was annulled before any money
 * arrived.
 *
 * Only an `active` supplement adds to the limit (ADR-0117 §3): a
 * purchase that was not paid for is not a right.
 */
enum SubscriptionSupplementStatus: string
{
    /** Bought, invoiced, not paid yet — the allowance is not granted. */
    case Pending = 'pending';

    /** Paid: the allowance is granted, and it shows in the entitlements. */
    case Active = 'active';

    /** The invoice was annulled before any money arrived. */
    case Canceled = 'canceled';

    /**
     * Does this supplement add to the limit of its meter?
     */
    public function grantsAllowance(): bool
    {
        return $this === self::Active;
    }
}
