<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

/**
 * The console always keeps one active platform administrator: without one, nobody can invite,
 * revoke or recover an account, and only the command line could bring the console back.
 */
final class LastPlatformAdmin extends RuntimeException
{
    public static function forOperator(string $operatorId): self
    {
        return new self(sprintf('Operator "%s" is the last active platform administrator: invite another one first.', $operatorId));
    }
}
