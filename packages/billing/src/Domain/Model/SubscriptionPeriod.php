<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use DateTimeImmutable;

/**
 * ADR-0072 §1 — a period of a contract.
 *
 * The periods of a contract never overlap: the database refuses any
 * overlap (`tstzrange(starts_at, ends_at)` with `btree_gist`), and the
 * bound is EXCLUSIVE — a period covers `[starts_at, ends_at)`. Two
 * consecutive periods therefore share their instant without overlapping,
 * and no day is ever lost or counted twice (ADR-0072 §4).
 *
 * A period is created BEFORE its deadline (ADR-0072 §4): it is born
 * `upcoming`, with its invoice, and becomes `current` when the previous
 * one ends. A payment made in advance settles that `upcoming` period — it
 * never creates a second one.
 */
final class SubscriptionPeriod
{
    public function __construct(
        public readonly string $id,
        public readonly string $subscriptionId,
        public readonly string $projectId,
        /** The rank of the period in its contract — unique, and gapless. */
        public int $seq,
        public PeriodKind $kind,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public PeriodState $state,
        /** The invoice of the period — only a `paid` period carries one. */
        public ?string $invoiceId,
        /** The motive — mandatory for a `complimentary` period (ADR-0072 §6). */
        public ?string $reason,
        public ?DateTimeImmutable $paidAt,
        public readonly DateTimeImmutable $createdAt,
    ) {}

    /**
     * The period is over.
     *
     * The row carries no `updated_at`: the infrastructure stamps it
     * itself, and the instant of the transition is handed to it.
     *
     * @param DateTimeImmutable $at the instant of the transition
     */
    public function close(DateTimeImmutable $at): void
    {
        $this->state = PeriodState::Closed;
    }

    /**
     * The period is the one running now.
     *
     * @param DateTimeImmutable $at the instant of the transition
     */
    public function makeCurrent(DateTimeImmutable $at): void
    {
        $this->state = PeriodState::Current;
    }

    /**
     * The period is prepared, waiting for the deadline (ADR-0072 §4).
     *
     * @param DateTimeImmutable $at the instant of the transition
     */
    public function markUpcoming(DateTimeImmutable $at): void
    {
        $this->state = PeriodState::Upcoming;
    }

    /**
     * The invoice of the period is settled.
     *
     * A `trial` or a `complimentary` period is free by nature, so marking
     * one paid is meaningless — but the domain does not refuse it: it is
     * the USE CASE that decides which kinds may be marked, and the Core
     * keeps no ledger of its own on that point.
     */
    public function markPaid(DateTimeImmutable $at): void
    {
        $this->paidAt = $at;
    }

    public function isPaid(): bool
    {
        return $this->paidAt !== null;
    }

    /**
     * Whether the period covers an instant — the bound is exclusive, so a
     * period ends exactly where the next one begins.
     */
    public function covers(DateTimeImmutable $moment): bool
    {
        return $this->startsAt <= $moment && $moment < $this->endsAt;
    }
}
