<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

/** One entry of a ledger transaction, with the account it touches (ADR-0076 §4). */
final readonly class LedgerTransactionLine
{
    public function __construct(
        public string $accountKind,
        public string $accountReference,
        public string $direction,
        public int $amountMinor,
        public string $currency,
    ) {}
}
