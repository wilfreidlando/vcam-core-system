<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Port;

use Core\Ledger\Domain\Model\AccountKind;
use Core\Ledger\Domain\Model\LedgerAccount;

interface LedgerAccountRepository
{
    public function save(LedgerAccount $account): void;

    public function find(string $id): ?LedgerAccount;

    public function findByComposite(string $projectId, AccountKind $kind, string $reference, string $currency): ?LedgerAccount;
}
