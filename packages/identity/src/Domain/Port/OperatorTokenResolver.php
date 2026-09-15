<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Port;

/**
 * The read counterpart of `OperatorTokenIssuer` — resolves a bearer token
 * back to an operator id. Kept as a separate port (rather than a method on
 * `OperatorTokenIssuer`) because issuing and resolving are used from
 * different call sites (login flow vs. every authenticated request) and
 * have independent test doubles.
 */
interface OperatorTokenResolver
{
    /**
     * @return string|null the operator id, or null if the token is
     *                     unknown, expired, or not an operator token
     */
    public function resolve(string $plaintextToken): ?string;
}
