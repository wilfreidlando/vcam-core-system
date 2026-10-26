<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

use Core\Support\Access\AccessScope;

/**
 * The operator console's project-scoped wallet listing (ADR-0045). Read-only,
 * same guarantee as `GetWalletBalance`: no mutation endpoint exists for
 * wallets (ADR-0033 §1).
 */
interface ListWallets
{
    /**
     * The scope is required (ADR-0069 §2): a listing never crosses the
     * projects the console account may see. A project outside the scope
     * yields an empty page — never another tenant's wallets, never a 403
     * (S-04).
     */
    public function list(ListWalletsCommand $command, AccessScope $scope): WalletPage;
}
