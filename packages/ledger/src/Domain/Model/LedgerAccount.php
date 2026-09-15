<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Model;

/**
 * A line in the chart of accounts (spec technique §6.4): a wallet, a
 * project-level clearing account, later a platform-fee or provider-fee
 * account. `reference` is `''` for a project-level account — never null
 * (the accounts table's uniqueness relies on that; see the migration).
 */
final readonly class LedgerAccount
{
    public function __construct(
        public string $id,
        public string $projectId,
        public AccountKind $kind,
        public string $reference,
        public string $currency,
    ) {}
}
