<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Exception;

use RuntimeException;

final class PaymentNotSimulable extends RuntimeException
{
    public static function notSandbox(string $paymentId): self
    {
        return new self(sprintf('Payment %s is not a simulated sandbox payment: only sandbox payments processed by the built-in simulator can be simulated.', $paymentId));
    }

    public static function alreadyFinal(string $paymentId, string $status): self
    {
        return new self(sprintf('Payment %s is already final (%s): it can no longer change.', $paymentId, $status));
    }
}
