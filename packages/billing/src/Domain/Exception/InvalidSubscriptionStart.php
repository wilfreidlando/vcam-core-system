<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0072 §1 — the anchor of a contract: the instant its periods are
 * computed from (ADR-0071 §4).
 *
 * The anchor is declared as an ISO-8601 timestamp; a value that is not
 * one is refused here rather than at the first renewal, where the
 * refusal would be far from the mistake.
 */
final class InvalidSubscriptionStart extends RuntimeException
{
    public static function becauseUnparsable(string $value): self
    {
        return new self("The start instant \"{$value}\" is not an ISO-8601 timestamp (ADR-0072 §1).");
    }
}
