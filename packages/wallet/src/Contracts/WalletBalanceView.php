<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

final readonly class WalletBalanceView
{
    public function __construct(
        public string $walletId,
        public int $availableMinor,
        public int $heldMinor,
        public string $currency,
    ) {}
}
