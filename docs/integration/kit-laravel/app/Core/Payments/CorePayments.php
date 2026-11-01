<?php

declare(strict_types=1);

namespace App\Core\Payments;

use App\Core\CoreClient;

/**
 * Collect money through the Core. You never talk to a provider: you give an
 * amount and a channel, the Core routes it (MTN, Orange, card…).
 *
 * Two flows (`flow`):
 *  - `paylink` : the Core returns a hosted page (`payment_url`) — send the payer there.
 *  - `payin`   : the payer gets a prompt on their phone (Mobile Money) — show `action_hint`.
 *
 * Nothing is certain until the event: wait for `payment.status_changed` with
 * `is_final = true` (see CoreEventReceived), or read the payment with `get()`.
 */
final class CorePayments
{
    public function __construct(private readonly CoreClient $core) {}

    /**
     * @param array{channel: string, amount: int, currency: string, reason: string, customer_phone_number?: string|null, customer_name?: string|null, customer_email?: string|null, customer_lang?: 'fr'|'en'|null, return_url?: string|null, callback_url?: string|null, wallet_recipient_external_ref?: string|null} $payment
     *                                                                                                                                                                                                                                                                                                                          `amount` is in the currency's smallest unit (XAF has none: 5000 = 5 000 FCFA).
     *                                                                                                                                                                                                                                                                                                                          `wallet_recipient_external_ref` credits that party's wallet when the payment succeeds.
     * @param string $key YOUR order id (or any stable id): a retry with the same key never charges twice
     * @return array{id: string, status: string, is_final: bool, payment_url?: string|null, action_hint?: string|null}
     */
    public function start(array $payment, string $key, string $flow = 'paylink'): array
    {
        /** @var array{id: string, status: string, is_final: bool, payment_url?: string|null, action_hint?: string|null} */
        return $this->core->post("payments/{$flow}", $payment, $key);
    }

    /**
     * @return array{id: string, status: string, is_final: bool, amount: int, currency: string, failure_reason?: string|null}
     */
    public function get(string $paymentId): array
    {
        /** @var array{id: string, status: string, is_final: bool, amount: int, currency: string, failure_reason?: string|null} */
        return $this->core->get("payments/{$paymentId}");
    }

    /**
     * Staging only (the Core's `PAYMENT_SIMULATOR_ENABLED`): force the outcome
     * of a payment, to test your event handling without a real phone.
     *
     * @param 'succeeded'|'failed'|'expired'|'canceled' $status
     * @return array<string, mixed>
     */
    public function simulate(string $paymentId, string $status, string $key, ?string $reason = null): array
    {
        return $this->core->post("payments/{$paymentId}/simulate", array_filter(['status' => $status, 'reason' => $reason]), $key);
    }

    /** A final status never changes again (hors `refunded` after a success). */
    public static function isFinal(string $status): bool
    {
        return in_array($status, ['succeeded', 'failed', 'expired', 'canceled', 'refunded'], true);
    }
}
