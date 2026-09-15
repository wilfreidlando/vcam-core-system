<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Wallet\Contracts\CreditWallet;
use Core\Wallet\Contracts\CreditWalletCommand;
use Core\Wallet\Contracts\WalletBalanceView;

final class FakeCreditWallet implements CreditWallet
{
    /** @var list<CreditWalletCommand> */
    public array $credited = [];

    public function credit(CreditWalletCommand $command): WalletBalanceView
    {
        $this->credited[] = $command;

        return new WalletBalanceView('wallet-fake-1', $command->amountMinor, 0, $command->currency);
    }
}
