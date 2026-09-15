<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Model;

use Core\Support\Money\Money;

/**
 * One posting: a direction and an amount against one account. Immutable —
 * matches the database's own append-only guarantee (ADR-0005).
 */
final readonly class LedgerEntry
{
    public function __construct(
        public string $accountId,
        public Direction $direction,
        public Money $amount,
    ) {}

    /**
     * Signed amount in minor units — credit positive, debit negative. What
     * the balance check (here and in the database trigger) actually sums.
     */
    public function signedAmountMinor(): int
    {
        return $this->amount->amountMinor() * $this->direction->sign();
    }
}
