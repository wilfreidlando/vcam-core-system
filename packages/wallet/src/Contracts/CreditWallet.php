<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

interface CreditWallet
{
    public function credit(CreditWalletCommand $command): WalletBalanceView;
}
