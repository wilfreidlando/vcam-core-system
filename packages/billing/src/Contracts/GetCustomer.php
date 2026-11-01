<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0070 §2 — `GET /v1/customers/{external_ref}`, one customer of one
 * project, read by the reference the project knows it by.
 *
 * The scope is a **required** parameter (ADR-0069 §2): a customer of
 * another project is simply not found — never told apart from one that
 * does not exist (ADR-0070 §Sécurité).
 */
interface GetCustomer
{
    public function get(GetCustomerCommand $command, AccessScope $scope): ?CustomerView;
}
