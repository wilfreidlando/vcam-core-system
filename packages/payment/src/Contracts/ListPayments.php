<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * The operator console's project-scoped payment listing (ADR-0045).
 * Reuses `PaymentView` as-is — the same read model `GetPaymentStatus`
 * already returns for a single payment.
 */
interface ListPayments
{
    public function list(ListPaymentsCommand $command): PaymentPage;
}
