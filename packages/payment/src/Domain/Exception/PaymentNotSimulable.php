<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Exception;

use RuntimeException;

final class PaymentNotSimulable extends RuntimeException
{
    public static function notSimulated(string $paymentId): self
    {
        return new self(sprintf('Payment %s was not processed by the built-in simulator: only a simulated payment can be simulated.', $paymentId));
    }

    public static function simulatorDisabled(): self
    {
        return new self('The payment simulator is not enabled on this deployment.');
    }

    public static function alreadyFinal(string $paymentId, string $status): self
    {
        return new self(sprintf('Payment %s is already final (%s): it can no longer change.', $paymentId, $status));
    }
}
