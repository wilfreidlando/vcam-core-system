<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

final class OperatorRevoked extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Operator "%s" has been revoked.', $id));
    }
}
