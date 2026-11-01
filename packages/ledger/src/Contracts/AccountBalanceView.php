<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

final readonly class AccountBalanceView
{
    public function __construct(
        public string $kind,
        public string $reference,
        public string $currency,
        public int $debitMinor,
        public int $creditMinor,
    ) {}

    /** Debits minus credits: positive on an asset (clearing), negative on a liability (wallet, revenue). */
    public function netDebitMinor(): int
    {
        return $this->debitMinor - $this->creditMinor;
    }
}
