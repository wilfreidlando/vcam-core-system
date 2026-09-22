<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

/**
 * A plain scalar predicate, not a Command-wrapped port — same shape as
 * `Core\Identity\Contracts\OperatorContext::hasPermission()` /
 * `ProjectContext::hasScope()`, the established pattern for a single-value
 * read check in this codebase.
 */
interface IsSupportedCurrency
{
    public function isSupported(string $currencyCode): bool;
}
