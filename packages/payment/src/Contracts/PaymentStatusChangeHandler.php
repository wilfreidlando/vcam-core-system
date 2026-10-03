<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Payment\Contracts\Events\PaymentStatusChanged;

/**
 * Applies a provider-reported status change to the Payment it belongs to
 * (resolved by `providerCode` + `transactionRef` — ADR-0038 §4/§5), then
 * publishes the canonical event to the outbox — all inside one
 * transaction (ADR-0036).
 *
 * Called today by `MyCoolPayWebhookController`; the same port is what a
 * future `checkStatus()`-based reconciliation job (missed webhooks) will
 * call too, so both paths converge on identical, tested behaviour.
 */
interface PaymentStatusChangeHandler
{
    /**
     * @param string $source what reported the change — `provider_callback`
     *                       (webhook), `reconciliation` (scheduled/admin
     *                       check), shown in the payment's "Parcours" (ADR-0059)
     */
    public function handle(PaymentStatusChanged $event, string $source = 'provider_callback'): void;
}
