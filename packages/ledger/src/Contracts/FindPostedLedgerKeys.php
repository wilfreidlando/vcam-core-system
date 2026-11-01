<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

/**
 * Which of these idempotency keys already have a transaction in a
 * project's ledger — one query for a batch, instead of
 * `LedgerPoster::hasBeenPosted()` per key.
 */
interface FindPostedLedgerKeys
{
    /**
     * @param list<string> $idempotencyKeys
     * @return list<string> the subset that is posted
     */
    public function findPosted(string $projectId, array $idempotencyKeys): array;
}
