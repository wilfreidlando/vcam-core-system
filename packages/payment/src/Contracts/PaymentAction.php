<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Support\Environment;

/**
 * Information submitted by the customer to satisfy the provider's
 * out-of-band authentication step (OTP code sent by SMS, 3DS
 * password, biometric confirmation, etc.).
 *
 * Each provider carries its own data shape (an OTP code, a 3DS
 * challenge response, a TAN). The adapter translates it into a
 * provider-specific body. The foundation stays generic.
 *
 * `projectId` lets a multi-tenant-aware adapter resolve this Project's
 * own provider application/credentials rather than a single
 * platform-wide key pair — mirrors `InitiatePaymentCommand::$projectId`.
 */
final readonly class PaymentAction
{
    public function __construct(
        public string $projectId,
        public string $transactionRef,
        public string $code,
        public Environment $environment = Environment::Live,
    ) {}
}
