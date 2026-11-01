<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

use Core\Support\Access\AccessScope;
use RuntimeException;

interface GetWalletBalance
{
    /**
     * @throws RuntimeException if the wallet does not exist, does not belong
     *                          to `requestingProjectId`, or is out of the
     *                          caller's scope — the cases are
     *                          indistinguishable on purpose (enumeration risk).
     *                          The concrete exception type lives in the Domain
     *                          layer; Contracts must not reference it (G3c).
     */
    public function get(GetWalletBalanceCommand $command, AccessScope $scope): WalletBalanceView;
}
