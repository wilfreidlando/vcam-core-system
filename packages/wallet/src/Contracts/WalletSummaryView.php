<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

/**
 * Unlike `WalletBalanceView` (returned by `GetWalletBalance` for a single,
 * known wallet), this carries the identifying fields a listing needs
 * (`partyId`, `currency`, `status`) alongside the balance.
 */
final readonly class WalletSummaryView
{
    public function __construct(
        public string $id,
        public string $partyId,
        public string $currency,
        public string $status,
        public int $availableMinor,
        public int $heldMinor,
        // The caller's own reference of the party (« escrow », « partner:7 »), when it is known.
        public ?string $partyExternalRef = null,
    ) {}
}
