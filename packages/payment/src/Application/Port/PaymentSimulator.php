<?php

declare(strict_types=1);

namespace Core\Payment\Application\Port;

use Core\Payment\Contracts\InitiatePaymentCommand;
use Core\Payment\Contracts\PaymentReceipt;

/**
 * ADR-0066 — the built-in payment simulator, a deployment-level testing
 * tool (enabled by `PAYMENT_SIMULATOR_ENABLED`, on the staging deployment,
 * never in production). When it is enabled, a payment whose project has no
 * application registered at the provider goes here: no outbound call, no
 * money. It stays `pending` until the tester simulates the outcome
 * (`POST /v1/payments/{id}/simulate`, or the hosted checkout page of a
 * paylink).
 */
interface PaymentSimulator
{
    public const PROVIDER_CODE = \Core\Payment\Domain\Model\Payment::SIMULATOR_PROVIDER_CODE;

    public function isEnabled(): bool;

    public function initiate(InitiatePaymentCommand $command): PaymentReceipt;
}
