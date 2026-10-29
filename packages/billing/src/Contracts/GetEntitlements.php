<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0074 §4 — `GET /v1/customers/{ref}/entitlements`.
 *
 * Null when the customer is not one of the project (G15: never told
 * apart from a customer that does not exist).
 */
interface GetEntitlements
{
    public function get(GetEntitlementsCommand $command, AccessScope $scope): ?EntitlementsView;
}
