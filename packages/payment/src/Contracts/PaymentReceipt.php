<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Payment\PaymentStatus;

/**
 * Result of one payment operation.
 *
 * The `transactionRef` is the provider-side reference returned by the
 * provider (always present after a successful initiate; required for
 * `checkStatus` and for OTP submission). The `status` is the canonical
 * lifecycle state, never a raw provider string (G6b).
 *
 * `paymentUrl` is set when the provider exposes a hosted payment page
 * (e.g. MyCoolPay paylink, Stripe Checkout). The caller should redirect
 * the customer to it when present.
 *
 * `requiresAction` indicates the provider is waiting for an out-of-band
 * step (OTP entry, QR scan, 3DS challenge). When true, `actionHint`
 * carries a provider-specific instruction (USSD code, action name,
 * etc.) the caller can surface to the customer.
 *
 * `errorMessage` is populated only on terminal failure; it is the
 * provider's diagnostic, never the canonical message.
 */
final readonly class PaymentReceipt
{
    public function __construct(
        public string $transactionRef,
        public PaymentStatus $status,
        public ?string $paymentUrl = null,
        public bool $requiresAction = false,
        public ?string $actionHint = null,
        public ?string $errorMessage = null,
    ) {}

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
