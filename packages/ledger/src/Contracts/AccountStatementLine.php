<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

use DateTimeImmutable;

/**
 * One line of a statement: an entry of the account, with the running
 * balance it leaves behind.
 *
 * `$direction` is the canonical `Direction` enum's `->value` ('debit' or
 * 'credit'), not the enum itself — same convention as `LedgerEntryView`.
 */
final readonly class AccountStatementLine
{
    public function __construct(
        public int $entryId,
        public string $transactionId,
        public string $transactionType,
        public ?string $externalRef,
        public DateTimeImmutable $createdAt,
        /** 'debit' | 'credit' — the `Direction` enum's value. */
        public string $direction,
        /** Minor units — never a float, per G5. */
        public int $amountMinor,
        /** Debits minus credits up to and including this line. */
        public int $balanceAfterMinor,
    ) {}
}
