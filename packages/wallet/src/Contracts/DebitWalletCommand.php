<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

/**
 * Debits an existing wallet. Unlike credit, a debit never lazily creates a
 * wallet — you cannot withdraw from an account that never received
 * anything.
 *
 * `projectId` is required, not just informational: the use case verifies
 * `walletId` actually belongs to it before debiting (security review
 * finding — this command used to carry no project at all, so nothing
 * stopped a caller from debiting a wallet it had no claim to by id alone).
 */
final readonly class DebitWalletCommand
{
    public function __construct(
        public string $projectId,
        public string $walletId,
        public int $amountMinor,
        public ?string $externalRef,
        public string $idempotencyKey,
    ) {}
}
