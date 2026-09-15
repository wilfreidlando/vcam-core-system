<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Model;

/**
 * Unlike `AccountKind`, this is a genuinely closed set: double-entry
 * bookkeeping has exactly two directions, always will.
 */
enum Direction: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    /**
     * +1 for credit, -1 for debit — the sign convention the balance check
     * (application-side and the database trigger) both use.
     */
    public function sign(): int
    {
        return match ($this) {
            self::Credit => 1,
            self::Debit => -1,
        };
    }
}
