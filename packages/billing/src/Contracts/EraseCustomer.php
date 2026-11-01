<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0070 §2 — `POST /v1/customers/{external_ref}/erase`: the identity
 * is gone (ADR-0083), the history stays.
 */
interface EraseCustomer
{
    /**
     * @throws RuntimeException if the customer is unknown or the
     *                          transition is not allowed. The concrete
     *                          exception type lives in the Domain layer;
     *                          Contracts must not reference it (G3c).
     */
    public function erase(EraseCustomerCommand $command): CustomerView;
}
