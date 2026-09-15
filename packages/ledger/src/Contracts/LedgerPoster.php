<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

/**
 * The only way another module writes to the ledger. Idempotent: replaying
 * the same `(projectId, idempotencyKey)` returns the original transaction
 * without posting a second one (ADR-0033 §2).
 *
 * Must be called inside the caller's own database transaction — the
 * deferred balance trigger evaluates at COMMIT, and a caller updating a
 * materialised balance (e.g. Wallet) needs that update to commit atomically
 * with these entries.
 */
interface LedgerPoster
{
    public function post(PostLedgerTransactionCommand $command): LedgerTransactionRef;

    /**
     * A caller that must decide something (e.g. "are funds sufficient?")
     * *before* it is safe to post needs to know it is a replay first — by
     * the time `post()` returns, entries have already been written for a
     * fresh call. See `Core\Wallet\Application\UseCase\DebitWalletUseCase`.
     */
    public function hasBeenPosted(string $projectId, string $idempotencyKey): bool;
}
