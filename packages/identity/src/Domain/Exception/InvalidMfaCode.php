<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

final class InvalidMfaCode extends RuntimeException
{
    public static function make(): self
    {
        return new self('The MFA code is invalid.');
    }
}
