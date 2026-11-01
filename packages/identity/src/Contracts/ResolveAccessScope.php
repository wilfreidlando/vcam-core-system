<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0069 §2 — computes what a console account may see, from its
 * platform role and its organization memberships.
 */
interface ResolveAccessScope
{
    public function forOperator(string $operatorId): AccessScope;
}
