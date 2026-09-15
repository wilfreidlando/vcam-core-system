<?php

declare(strict_types=1);

namespace Core\Wallet\Domain\Exception;

use RuntimeException;

final class InsufficientFunds extends RuntimeException
{
    public static function forWallet(string $walletId): self
    {
        return new self("Wallet {$walletId} does not have sufficient available funds.");
    }
}
