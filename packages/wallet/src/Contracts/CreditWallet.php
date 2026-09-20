<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

use RuntimeException;

interface CreditWallet
{
    /**
     * @throws RuntimeException if `$command->partyId` already has a wallet
     *                          in `$command->currency` under a different project. The concrete
     *                          exception type lives in the Domain layer; Contracts must not
     *                          reference it (G3c).
     */
    public function credit(CreditWalletCommand $command): WalletBalanceView;
}
