<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

/**
 * Credits the wallet belonging to `(projectId, partyId)` in `currency`,
 * creating it lazily if this is its first credit (spec fonctionnelle §4.3
 * applied to wallets, not just Parties).
 */
final readonly class CreditWalletCommand
{
    public function __construct(
        public string $projectId,
        public string $partyId,
        public int $amountMinor,
        public string $currency,
        public ?string $externalRef,
        public string $idempotencyKey,
        // ADR-0065 — the environment of the operation that credits: a
        // sandbox payment credits a sandbox wallet, never a live one.
        public string $environment = 'live',
        // The product's own reference for the party, echoed in the
        // `wallet.credited` event when known.
        public ?string $partyExternalRef = null,
    ) {}
}
