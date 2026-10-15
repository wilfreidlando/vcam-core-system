<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

final readonly class PartyWalletView
{
    public function __construct(
        public string $walletId,
        public string $partyExternalRef,
        public string $currency,
        public int $availableMinor,
        public int $heldMinor,
        public string $status,
    ) {}
}
