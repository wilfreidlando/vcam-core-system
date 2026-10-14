<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

use RuntimeException;

interface RecordWithdrawal
{
    /**
     * Idempotent on `(projectId, idempotencyKey)`.
     *
     * @throws RuntimeException the party lacks funds (concrete type in the Domain layer, G3c)
     */
    public function record(RecordWithdrawalCommand $command): WalletOperationView;
}
