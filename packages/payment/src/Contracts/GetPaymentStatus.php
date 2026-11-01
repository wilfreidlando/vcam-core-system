<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Support\Access\AccessScope;
use RuntimeException;

interface GetPaymentStatus
{
    /**
     * The scope is required (ADR-0069 §2). The public API resolves the
     * calling project's own scope (`AccessScope::forProject()`); the console
     * passes the account's.
     *
     * @throws RuntimeException if the payment does not exist, or does not
     *                          belong to `requestingProjectId` — the two cases are
     *                          indistinguishable on purpose (enumeration risk, same
     *                          convention as `Core\Wallet\Contracts\GetWalletBalance`).
     *                          Reads the Core's own state only — no live
     *                          reconciliation against the provider yet; that is
     *                          future work, not part of this pass.
     */
    public function get(GetPaymentStatusCommand $command, AccessScope $scope): PaymentView;
}
