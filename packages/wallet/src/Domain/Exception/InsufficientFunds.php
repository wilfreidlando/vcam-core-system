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

    /**
     * ADR-0065 — the source of a transfer or withdrawal, named by the
     * caller's own reference: a party with no wallet in this currency has
     * nothing to spend, which is the same answer as an empty wallet.
     */
    public static function forParty(string $externalRef, string $currency): self
    {
        return new self("Party \"{$externalRef}\" does not have sufficient available funds in {$currency}.");
    }
}
