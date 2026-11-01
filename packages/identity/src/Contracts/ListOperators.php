<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use Core\Support\Access\AccessScope;

/**
 * The console's operator listing (ADR-0050 — the self-service management
 * of platform operators, JSON and web console).
 *
 * The listing is a **tenant-scoped** read: an account with a platform role
 * sees every operator, an account with only organization memberships sees
 * the operators of the organizations it belongs to — and nobody else's
 * (ADR-0069 §2). The filter is applied in the query, so the operators
 * outside the scope are never materialised and the paginated `total`
 * counts the scoped rows only.
 */
interface ListOperators
{
    /**
     * The scope is required (ADR-0069 §2): a listing never crosses the
     * organizations the console account may see. An operator outside the
     * scope is simply absent from the listing — never a 403 (S-04).
     */
    public function list(ListOperatorsCommand $command, AccessScope $scope): OperatorPage;
}
