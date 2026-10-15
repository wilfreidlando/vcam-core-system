<?php

declare(strict_types=1);

namespace Core\Wallet\Domain\Exception;

use RuntimeException;

/**
 * A transfer or withdrawal whose shape breaks a rule the HTTP validation
 * cannot see on its own (a party paying itself, a leg in another currency…).
 */
final class InvalidWalletOperation extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
