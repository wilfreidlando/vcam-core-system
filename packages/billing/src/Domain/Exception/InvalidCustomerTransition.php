<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0070 §1 — the life cycle of a customer: `active`, `suspended`,
 * `erased`.
 *
 * An erased customer is final (ADR-0083): the identity is gone, the row
 * stays. It can therefore be neither suspended nor reactivated — the
 * history it carries is all that is left of it.
 */
final class InvalidCustomerTransition extends RuntimeException
{
    public static function becauseNotAllowed(string $from, string $to): self
    {
        return new self("A customer in state \"{$from}\" cannot move to \"{$to}\".");
    }
}
