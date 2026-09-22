<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Port;

/**
 * The `currencies` table (spec technique §6.2) is the Core's one explicit
 * currency allow-list — every currency column elsewhere either FKs to it
 * directly (`ledger_accounts`) or, where a cross-module FK isn't possible
 * (`payments`, `wallets` — ADR-0033 §5), is meant to be checked against it
 * at the Application layer instead.
 */
interface CurrencyRepository
{
    public function isSupported(string $code): bool;
}
