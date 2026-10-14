<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

use RuntimeException;

interface ListWalletTransactions
{
    /**
     * @throws RuntimeException the wallet does not exist, or belongs to another project or environment
     */
    public function list(ListWalletTransactionsCommand $command): WalletTransactionPage;
}
