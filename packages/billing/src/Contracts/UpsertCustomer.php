<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0070 §2 — the project declares its customers:
 * `PUT /v1/customers/{external_ref}` creates (`201`) or updates (`200`),
 * idempotent by construction.
 *
 * `expectedVersion` is the optimistic lock of ADR-0070 §2: the caller
 * sends the version it last read and the write is refused when the
 * stored one has moved on (`412 version-conflict`).
 */
interface UpsertCustomer
{
    /**
     * @throws RuntimeException if the write is refused — an unknown
     *                          project, an email already taken in that
     *                          project, a version conflict. The concrete
     *                          exception type lives in the Domain layer;
     *                          Contracts must not reference it (G3c).
     */
    public function upsert(UpsertCustomerCommand $command): CustomerView;
}
