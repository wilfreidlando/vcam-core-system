<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use Core\Identity\OperatorRole;
use RuntimeException;

final class InvalidOperatorRole extends RuntimeException
{
    public static function withValue(string $value): self
    {
        $valid = implode(', ', array_column(OperatorRole::cases(), 'value'));

        return new self(sprintf('"%s" is not a valid operator role. Must be one of: %s.', $value, $valid));
    }
}
