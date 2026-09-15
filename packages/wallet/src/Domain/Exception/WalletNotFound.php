<?php

declare(strict_types=1);

namespace Core\Wallet\Domain\Exception;

use RuntimeException;

final class WalletNotFound extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self("Wallet {$id} does not exist.");
    }
}
