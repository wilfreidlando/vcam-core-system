<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0117 §4 — `POST /v1/subscriptions/{id}/supplements`: the purchase
 * of units added on top of the limit of a metered feature.
 *
 * The purchase is **not** a second subscription and **not** a renewal
 * (ADR-0074 §3): the customer keeps his plan, his period and his
 * counter; the purchase adds a quantity to the limit of one meter and
 * carries its own invoice.
 *
 * The allowance is granted **when that invoice is paid** (ADR-0117 §2) —
 * the order alone grants nothing.
 */
interface PurchaseSubscriptionSupplement
{
    /**
     * @throws RuntimeException if the purchase cannot be made — an
     *                          unknown contract, a contract that grants
     *                          no right, a meter that the plan of the
     *                          contract does not carry, a meter without
     *                          a limit, a quantity that is not positive
     *                          or a negative price. The concrete
     *                          exception type lives in the Domain layer;
     *                          Contracts must not reference it (G3c).
     */
    public function purchase(PurchaseSubscriptionSupplementCommand $command): SubscriptionSupplementPurchaseView;
}
