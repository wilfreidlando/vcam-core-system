<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

/**
 * ADR-0076 §2 — what the reclassification of the historical
 * project-level `clearing` account actually did.
 *
 * A bare "moved / not moved" would hide the difference between "the
 * money is on the provider's account now" and "this was already done
 * once, and the account is not empty any more" — the second one is a
 * human decision, not a no-op.
 */
enum ClearingReclassificationOutcome
{
    /** The money left the historical account for the provider's account. */
    case Moved;

    /**
     * The idempotency key was already used: the reclassification
     * happened once, and running it again moves nothing.
     */
    case AlreadyPosted;

    /**
     * Nothing could be booked — an amount of zero, or a currency the
     * ledger does not support.
     */
    case Unbookable;
}
