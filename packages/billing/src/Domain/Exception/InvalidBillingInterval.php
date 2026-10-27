<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0071 §1 — the interval of a plan version is `unit` + `count`, and
 * the count is at least one.
 *
 * A zero or negative count has no meaning: the period would be empty, and
 * the exclusion constraint on the periods (`ends_at > starts_at`) would
 * refuse the row it produced.
 */
final class InvalidBillingInterval extends RuntimeException
{
    public static function becauseCountBelowOne(int $count): self
    {
        return new self("A billing interval count must be at least one (got {$count}).");
    }
}
