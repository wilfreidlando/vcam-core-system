<?php

declare(strict_types=1);

namespace Core\Payment\Application\Port;

use Core\Payment\Contracts\InitiatePaymentCommand;
use Core\Payment\Contracts\PaymentReceipt;

/**
 * ADR-0060 / decision D2 — what a sandbox payment calls when its project
 * has no sandbox application at the provider: no outbound call, no real
 * money. The payment stays `pending` until the integrator simulates the
 * outcome (`POST /v1/sandbox/payments/{id}/simulate`, or the hosted
 * sandbox checkout page for a paylink).
 */
interface SandboxPaymentSimulator
{
    public const PROVIDER_CODE = 'sandbox';

    public function initiate(InitiatePaymentCommand $command): PaymentReceipt;
}
