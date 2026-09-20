<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Exception;

use InvalidArgumentException;

/**
 * An entry's magnitude was zero or negative. The database's own
 * `CHECK (amount_minor > 0)` constraint on `ledger_entries` would also
 * catch this at commit time (defence in depth), but relying on it alone
 * meant a bad amount surfaced as an unhandled `QueryException` from deep
 * inside a transaction instead of a clean rejection before a single row
 * is written (security review finding).
 */
final class NonPositiveLedgerEntryAmount extends InvalidArgumentException
{
    public static function forAccount(string $accountId): self
    {
        return new self("Ledger entry for account {$accountId} must have a positive amount.");
    }
}
