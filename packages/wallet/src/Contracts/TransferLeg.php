<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

/**
 * One movement of a transfer (ADR-0065): `amountMinor` leaves the wallet
 * of the party `from` and enters the wallet of the party `to`, both named
 * by the product's own references. `toPartyType` only matters when the
 * destination party does not exist yet and is created lazily.
 */
final readonly class TransferLeg
{
    public function __construct(
        public string $from,
        public string $to,
        public int $amountMinor,
        public ?string $toPartyType = null,
    ) {}
}
