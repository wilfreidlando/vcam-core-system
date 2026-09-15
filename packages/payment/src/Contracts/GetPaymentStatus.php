<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use RuntimeException;

interface GetPaymentStatus
{
    /**
     * @throws RuntimeException if the payment does not exist, or does not
     *                          belong to `requestingProjectId` — the two cases are
     *                          indistinguishable on purpose (enumeration risk, same
     *                          convention as `Core\Wallet\Contracts\GetWalletBalance`).
     *                          Reads the Core's own state only — no live
     *                          reconciliation against the provider yet; that is
     *                          future work, not part of this pass.
     */
    public function get(GetPaymentStatusCommand $command): PaymentView;
}
