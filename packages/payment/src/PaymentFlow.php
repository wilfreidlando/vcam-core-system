<?php

declare(strict_types=1);

namespace Core\Payment;

/**
 * Discriminates between the two payment initiation flows exposed by
 * aggregator-style providers.
 *
 * `Payin` is the synchronous, app-driven flow (used by mobile apps and
 * server-to-server integrations that drive the customer through their
 * own UX). MyCoolPay exposes this as `POST /payin`; CinetPay exposes
 * it as `POST /v2/payment`; MTN exposes it as `POST /collection/.../requesttopay`.
 *
 * `Paylink` is the hosted-link, redirect-driven flow (used by web apps
 * that hand the customer off to a provider-hosted page). MyCoolPay
 * exposes this as `POST /paylink`; CinetPay returns a `payment_url`
 * alongside the same initiation call (the discriminator is implicit).
 *
 * Providers that do not distinguish the two flows (e.g. CinetPay) treat
 * `Payin` as the default and ignore the discriminator on the
 * `InitiatePaymentCommand`.
 */
enum PaymentFlow: string
{
    case Payin = 'payin';
    case Paylink = 'paylink';
}
