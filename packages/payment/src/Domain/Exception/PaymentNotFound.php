<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Exception;

use RuntimeException;

final class PaymentNotFound extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self("Payment {$id} does not exist.");
    }
}
