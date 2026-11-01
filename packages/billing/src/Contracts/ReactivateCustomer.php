<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0070 §2 — `POST /v1/customers/{external_ref}/reactivate`: the
 * customer gets its rights back (ADR-0072 §3).
 */
interface ReactivateCustomer
{
    /**
     * @throws RuntimeException if the customer is unknown or the
     *                          transition is not allowed — an erased
     *                          customer cannot be reactivated. The
     *                          concrete exception type lives in the
     *                          Domain layer; Contracts must not
     *                          reference it (G3c).
     */
    public function reactivate(ReactivateCustomerCommand $command): CustomerView;
}
