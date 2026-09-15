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
    ) {}
}
