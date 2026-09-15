<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

/**
 * Debits an existing wallet. Unlike credit, a debit never lazily creates a
 * wallet — you cannot withdraw from an account that never received
 * anything.
 */
final readonly class DebitWalletCommand
{
    public function __construct(
        public string $walletId,
        public int $amountMinor,
        public ?string $externalRef,
        public string $idempotencyKey,
    ) {}
}
