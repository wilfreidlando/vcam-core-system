<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

final class OperatorNotFound extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('No operator "%s" exists.', $id));
    }
}
