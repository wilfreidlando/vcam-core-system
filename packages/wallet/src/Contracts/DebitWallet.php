<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

interface DebitWallet
{
    public function debit(DebitWalletCommand $command): WalletBalanceView;
}
