<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

/**
 * The public-facing mirror of `Core\Ledger\Domain\Model\Direction` — kept
 * separate so a caller constructing a posting never needs the Domain type
 * (G3c: other modules reach this module through Contracts/ only).
 */
enum Direction: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
