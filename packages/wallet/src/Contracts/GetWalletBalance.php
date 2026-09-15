<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

use RuntimeException;

interface GetWalletBalance
{
    /**
     * @throws RuntimeException if the wallet does not exist, or does not belong
     *                          to `requestingProjectId` — the two cases are
     *                          indistinguishable on purpose (enumeration risk).
     *                          The concrete exception type lives in the Domain
     *                          layer; Contracts must not reference it (G3c).
     */
    public function get(GetWalletBalanceCommand $command): WalletBalanceView;
}
