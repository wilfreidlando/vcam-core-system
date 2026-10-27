<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0071 §4 — the settings of a project carry the timezone the periods
 * are computed in.
 *
 * An unknown timezone is refused here rather than at the first renewal: a
 * contract whose periods cannot be computed is worse than a settings row
 * that is rejected outright.
 */
final class InvalidBillingSettings extends RuntimeException
{
    public static function becauseUnknownTimezone(string $timezone): self
    {
        return new self("\"{$timezone}\" is not a known timezone.");
    }
}
