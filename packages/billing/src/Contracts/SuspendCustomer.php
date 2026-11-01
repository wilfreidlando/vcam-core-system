<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0070 §2 — `POST /v1/customers/{external_ref}/suspend`: the customer
 * loses its rights (ADR-0072 §3) but its invoices stay due.
 */
interface SuspendCustomer
{
    /**
     * @throws RuntimeException if the customer is unknown or the
     *                          transition is not allowed — an erased
     *                          customer cannot be suspended. The concrete
     *                          exception type lives in the Domain layer;
     *                          Contracts must not reference it (G3c).
     */
    public function suspend(SuspendCustomerCommand $command): CustomerView;
}
