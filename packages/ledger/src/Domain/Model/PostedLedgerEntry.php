<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Model;

use Core\Support\Money\Money;
use DateTimeImmutable;

/**
 * One historical entry as read back for an account, with just enough of
 * its parent transaction's context (`transactionType`, `externalRef`) for
 * a reader to understand WHY it was posted — distinct from `LedgerEntry`,
 * the write-side value object used to construct a new `LedgerTransaction`
 * before it exists, which has no id, no timestamp, and no transaction
 * context of its own.
 */
final readonly class PostedLedgerEntry
{
    public function __construct(
        public int $id,
        public string $transactionId,
        public string $transactionType,
        public ?string $externalRef,
        public Direction $direction,
        public Money $amount,
        public DateTimeImmutable $createdAt,
    ) {}
}
