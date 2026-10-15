<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

use RuntimeException;

interface TransferFunds
{
    /**
     * Idempotent on `(projectId, idempotencyKey)`: a replay
     * returns the original operation and moves nothing.
     *
     * @throws RuntimeException a source lacks funds, or the legs break a rule
     *                          (a party paying itself, too many legs…). The concrete
     *                          types live in the Domain layer (G3c).
     */
    public function transfer(TransferFundsCommand $command): WalletOperationView;
}
