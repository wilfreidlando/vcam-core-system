<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

use RuntimeException;

interface DebitWallet
{
    /**
     * @throws RuntimeException if `$command->walletId` does not exist, or exists
     *                          under a different project than `$command->projectId`. The
     *                          concrete exception types live in the Domain layer; Contracts
     *                          must not reference them (G3c).
     */
    public function debit(DebitWalletCommand $command): WalletBalanceView;
}
