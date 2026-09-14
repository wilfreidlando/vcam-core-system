<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * Information submitted by the customer to satisfy the provider's
 * out-of-band authentication step (OTP code sent by SMS, 3DS
 * password, biometric confirmation, etc.).
 *
 * Each provider carries its own data shape (an OTP code, a 3DS
 * challenge response, a TAN). The adapter translates it into a
 * provider-specific body. The foundation stays generic.
 */
final readonly class PaymentAction
{
    public function __construct(
        public string $transactionRef,
        public string $code,
    ) {}
}
