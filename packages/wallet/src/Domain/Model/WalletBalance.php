<?php

declare(strict_types=1);

namespace Core\Wallet\Domain\Model;

use Core\Support\Money\Money;

/**
 * The materialised view spec technique §6.3 describes: a cache of the
 * ledger's truth, updated only under a row lock, in the same transaction as
 * the entries that justify the new numbers (ADR-0006).
 */
final readonly class WalletBalance
{
    public function __construct(
        public string $walletId,
        public Money $available,
        public Money $held,
        public int $version,
    ) {}
}
