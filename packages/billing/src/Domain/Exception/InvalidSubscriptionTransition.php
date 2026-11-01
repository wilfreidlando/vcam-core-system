<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0072 §2 — a transition the state machine of a contract does not
 * allow.
 *
 * The message states the rule that was broken, because the API layer turns
 * it into an RFC 7807 problem detail (`app/Http/ExceptionProblemMapping.php`).
 */
final class InvalidSubscriptionTransition extends RuntimeException
{
    public static function becauseNotAllowed(string $from, string $to): self
    {
        return new self("A subscription in state \"{$from}\" cannot move to \"{$to}\".");
    }

    /**
     * ADR-0072 §6 — a pause is an operator act, and an operator act is an
     * audited act: it is filed with a reason, never without one.
     */
    public static function becausePauseWithoutReason(): self
    {
        return new self('A pause requires a reason: an operator act is an audited act (ADR-0072 §6).');
    }
}
