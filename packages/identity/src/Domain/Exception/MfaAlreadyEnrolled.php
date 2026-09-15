<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

final class MfaAlreadyEnrolled extends RuntimeException
{
    public static function forOperator(string $operatorId): self
    {
        return new self(sprintf('Operator "%s" already has MFA enabled.', $operatorId));
    }
}
