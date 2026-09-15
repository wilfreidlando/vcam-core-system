<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Payment\PaymentFlow;

/**
 * Command handed to a payment provider to start a payment.
 *
 * Amounts are in **minor units** (e.g. XAF has 0 decimals so 1 unit = 1 XAF;
 * EUR has 2 decimals so 1 unit = 0.01 EUR). The provider adapter is
 * responsible for serialising the amount in whatever format the
 * underlying API expects (some take a string, some take an integer).
 *
 * Fields the provider doesn't need may be left null (e.g. some
 * providers don't ask for the customer's email). The caller is
 * responsible for validating presence per provider before submitting.
 *
 * The `flow` discriminator chooses between the synchronous app-driven
 * flow (`Payin`) and the hosted-link redirect flow (`Paylink`). It is
 * honoured by providers that expose both (MyCoolPay); providers that
 * don't distinguish (CinetPay) ignore it.
 */
final readonly class InitiatePaymentCommand
{
    public function __construct(
        public int $amountMinorUnits,
        public string $currency,
        public string $reason,
        public string $appTransactionRef,
        public ?string $customerPhoneNumber = null,
        public ?string $customerName = null,
        public ?string $customerEmail = null,
        public ?string $customerLang = null,
        public PaymentFlow $flow = PaymentFlow::Payin,
    ) {}
}
