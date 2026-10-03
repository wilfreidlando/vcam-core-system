<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use RuntimeException;

/**
 * ADR-0060 / decision D2 — moves a simulated sandbox payment to the
 * outcome the integrator chose, through exactly the same path a real
 * provider callback takes (status change, journey, event, webhooks,
 * callback_url) — so the integration is tested end to end without money.
 */
interface SimulateSandboxPayment
{
    /**
     * @throws RuntimeException payment unknown for this project
     *                          (`PaymentNotFound`), or not a simulated
     *                          sandbox payment (`PaymentNotSimulable`)
     */
    public function simulate(SimulateSandboxPaymentCommand $command): PaymentView;
}
