<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

/**
 * The operator console's project-scoped wallet listing (ADR-0045). Read-only,
 * same guarantee as `GetWalletBalance`: no mutation endpoint exists for
 * wallets (ADR-0033 §1).
 */
interface ListWallets
{
    public function list(ListWalletsCommand $command): WalletPage;
}
