<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Support\Access\AccessScope;

/**
 * The operator console's project-scoped payment listing (ADR-0045).
 * Reuses `PaymentView` as-is — the same read model `GetPaymentStatus`
 * already returns for a single payment.
 */
interface ListPayments
{
    /**
     * The scope is required (ADR-0069 §2): a listing never crosses the
     * projects the console account may see.
     */
    public function list(ListPaymentsCommand $command, AccessScope $scope): PaymentPage;
}
