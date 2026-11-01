<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0072 §2 — the confirmation that the invoice of a period was paid:
 * the contract leaves `incomplete`/`past_due` for `active`.
 */
interface ConfirmSubscriptionPeriodPayment
{
    /**
     * @throws RuntimeException if the payment cannot be confirmed — an
     *                          unknown contract or period, a period
     *                          already settled. The concrete exception
     *                          type lives in the Domain layer; Contracts
     *                          must not reference it (G3c).
     */
    public function confirm(ConfirmSubscriptionPeriodPaymentCommand $command): SubscriptionView;
}
