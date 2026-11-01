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
        // The product's own reference for the party, echoed in the
        // `wallet.credited` event when known.
        public ?string $partyExternalRef = null,
        /**
         * Where the money comes from — ADR-0076 §2: the debit lands on
         * `clearing:<prestataire>` when the provider holds it, on the
         * exception pool when it waits to be imputed (ADR-0077 §4).
         *
         * `null` keeps the historical behaviour: the project-level
         * `clearing` account (reference `''`) is debited.
         */
        public ?WalletCreditOrigin $origin = null,
    ) {}
}
