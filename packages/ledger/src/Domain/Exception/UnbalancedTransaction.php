<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Exception;

use InvalidArgumentException;

/**
 * Sum(credits) != sum(debits). The database's deferred constraint trigger
 * would also catch this at commit time (defence in depth), but this
 * exception means it never gets that far — the invariant is checked before
 * a single row is written.
 */
final class UnbalancedTransaction extends InvalidArgumentException
{
    public static function withImbalance(int $imbalanceMinor): self
    {
        return new self("Ledger transaction does not balance: credits minus debits = {$imbalanceMinor} minor units.");
    }
}
