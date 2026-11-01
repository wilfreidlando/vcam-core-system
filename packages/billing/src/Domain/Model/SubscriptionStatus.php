<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0072 §2 — the state machine of a contract.
 *
 * The table of the transitions is the ONE authority: every transition of
 * the module goes through `canTransitionTo()`, so the console, the API and
 * the scheduler cannot disagree on what a contract may become.
 *
 * `canceled` and `expired` are final: a dead contract stays dead.
 */
enum SubscriptionStatus: string
{
    case Incomplete = 'incomplete';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Paused = 'paused';
    case Canceled = 'canceled';
    case Expired = 'expired';

    /**
     * ADR-0072 §2 — the transitions the table allows.
     *
     * - `incomplete` → `active`, `expired`, `canceled`, `paused`;
     * - `trialing` → `active`, `past_due`, `expired`, `canceled`, `paused`;
     * - `active` → `past_due`, `paused`, `canceled`;
     * - `past_due` → `active`, `expired`, `paused`, `canceled`;
     * - `paused` → `active`, `canceled`;
     * - `canceled`, `expired` → nothing: they are final.
     */
    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Incomplete => in_array($to, [self::Active, self::Expired, self::Canceled, self::Paused], true),
            self::Trialing => in_array($to, [self::Active, self::PastDue, self::Expired, self::Canceled, self::Paused], true),
            self::Active => in_array($to, [self::PastDue, self::Paused, self::Canceled], true),
            self::PastDue => in_array($to, [self::Active, self::Expired, self::Paused, self::Canceled], true),
            self::Paused => in_array($to, [self::Active, self::Canceled], true),
            self::Canceled, self::Expired => false,
        };
    }

    /**
     * `canceled` and `expired` close a contract for good (ADR-0072 §2).
     */
    public function isFinal(): bool
    {
        return $this === self::Canceled || $this === self::Expired;
    }

    /**
     * ADR-0072 §3 — the rights follow the state, not the other way round.
     *
     * A contract grants its rights while it is `trialing` or `active`, and
     * while it is `past_due` **only** if the plan version grants the grace
     * (`grace_access`). An `incomplete`, `paused`, `canceled` or `expired`
     * contract grants none.
     */
    public function grantsAccess(bool $graceAccess): bool
    {
        return match ($this) {
            self::Trialing, self::Active => true,
            self::PastDue => $graceAccess,
            default => false,
        };
    }
}
