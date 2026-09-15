<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

final readonly class LedgerEntryInput
{
    public function __construct(
        public string $accountId,
        public Direction $direction,
        public int $amountMinor,
        public string $currency,
    ) {}
}
