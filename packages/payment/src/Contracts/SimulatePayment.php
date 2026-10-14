<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use RuntimeException;

/**
 * ADR-0066 — moves a simulated payment to the outcome the tester chose,
 * through exactly the same path a real provider callback takes (status
 * change, journey, wallet credit, event, webhooks, callback_url) — so the
 * integration is tested end to end without money, on a deployment where
 * the simulator is enabled.
 */
interface SimulatePayment
{
    /**
     * @throws RuntimeException payment unknown for this project
     *                          (`PaymentNotFound`), not simulated, or the
     *                          simulator is disabled (`PaymentNotSimulable`)
     */
    public function simulate(SimulatePaymentCommand $command): PaymentView;
}
