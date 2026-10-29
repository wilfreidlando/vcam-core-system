<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use Core\Billing\Domain\Exception\InvalidSubscriptionTransition;
use DateTimeImmutable;

/**
 * ADR-0072 §1 — the contract between a customer and a project.
 *
 * ONE contract renews itself through its periods; it is never replaced by
 * another one. The model that "a new subscription per period" breaks on
 * the very first advance payment (two live contracts overlapping, the
 * history scattered) is the one this aggregate replaces.
 *
 * The terms are FROZEN on the contract: the price, the currency and the
 * interval come from the plan version the contract was signed on, and a
 * later version of the same plan never touches them (ADR-0071
 * §Conséquences). Only an explicit change of plan moves them, and that
 * change is frozen too.
 *
 * The state machine of ADR-0072 §2 is the one authority: every transition
 * goes through `SubscriptionStatus::canTransitionTo()`, so the console,
 * the API and the scheduler cannot disagree on what a contract may become.
 *
 * The `version` is the optimistic lock: every transition is an
 * `UPDATE … WHERE id = ? AND status = :expected AND version = :v`, and a
 * zero row updated means the state moved under our feet — a conflict, or
 * a replay to recognise as such. It is bumped on every mutation of the
 * contract, not only on the status changes, because the lock protects the
 * whole row.
 */
final class Subscription
{
    public function __construct(
        public readonly string $id,
        public readonly string $projectId,
        public readonly string $customerId,
        public string $planId,
        public string $planVersionId,
        public SubscriptionStatus $status,
        /** The price in minor units of `currency` (G5). */
        public int $priceMinor,
        public string $currency,
        public BillingInterval $interval,
        /** The anchor the periods are computed from (ADR-0071 §4). */
        public readonly DateTimeImmutable $anchorAt,
        public ?string $currentPeriodId,
        public ?DateTimeImmutable $trialEndsAt,
        public bool $cancelAtPeriodEnd,
        /** The plan version that will take over at the end of the period. */
        public ?string $pendingPlanVersionId,
        public ?DateTimeImmutable $pausedAt,
        public ?DateTimeImmutable $canceledAt,
        public ?DateTimeImmutable $endedAt,
        public int $version,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * The first invoice is paid: the contract runs (ADR-0072 §2).
     */
    public function activate(DateTimeImmutable $at): void
    {
        $this->assertCanTransitionTo(SubscriptionStatus::Active);

        $this->status = SubscriptionStatus::Active;
        $this->touch($at);
    }

    /**
     * The renewal invoice is open and unpaid: the contract is late, and
     * the grace of the plan version decides whether the rights survive
     * (ADR-0072 §3).
     */
    public function markPastDue(DateTimeImmutable $at): void
    {
        $this->assertCanTransitionTo(SubscriptionStatus::PastDue);

        $this->status = SubscriptionStatus::PastDue;
        $this->touch($at);
    }

    /**
     * The grace ran out without a payment: the contract is over, and
     * `ended_at` says when (ADR-0072 §2).
     */
    public function expire(DateTimeImmutable $at): void
    {
        $this->assertCanTransitionTo(SubscriptionStatus::Expired);

        $this->status = SubscriptionStatus::Expired;
        $this->endedAt = $at;
        $this->touch($at);
    }

    /**
     * ADR-0072 §6 — a pause is an operator act, and an operator act is an
     * audited act: it is filed with a reason, never without one.
     *
     * The reason is not kept on the contract — the row has no column for
     * it — the audit trail keeps it; the domain only refuses a blank one.
     */
    public function pause(DateTimeImmutable $at, string $reason): void
    {
        if (trim($reason) === '') {
            throw InvalidSubscriptionTransition::becausePauseWithoutReason();
        }

        $this->assertCanTransitionTo(SubscriptionStatus::Paused);

        $this->status = SubscriptionStatus::Paused;
        $this->pausedAt = $at;
        $this->touch($at);
    }

    /**
     * The operator takes the contract back: it runs again.
     *
     * The prolongation of the current period by the duration of the pause
     * (ADR-0072 §2) is the use case's job — the contract alone does not
     * know where its current period ends.
     */
    public function resume(DateTimeImmutable $at): void
    {
        $this->assertCanTransitionTo(SubscriptionStatus::Active);

        $this->status = SubscriptionStatus::Active;
        $this->pausedAt = null;
        $this->touch($at);
    }

    /**
     * ADR-0072 §2 — an immediate cancellation, or a cancellation at the
     * end of the current period.
     *
     * A cancellation at the end of the period does NOT change the status:
     * the contract still lives until its period ends (the status becomes
     * `canceled` then, and not before). It only arms the flag, and the
     * contract keeps its rights until the deadline.
     */
    public function cancel(DateTimeImmutable $at, bool $atPeriodEnd): void
    {
        if ($atPeriodEnd) {
            if ($this->status->isFinal()) {
                throw InvalidSubscriptionTransition::becauseNotAllowed(
                    $this->status->value,
                    SubscriptionStatus::Canceled->value,
                );
            }

            $this->cancelAtPeriodEnd = true;
            $this->touch($at);

            return;
        }

        $this->assertCanTransitionTo(SubscriptionStatus::Canceled);

        $this->status = SubscriptionStatus::Canceled;
        $this->canceledAt = $at;
        $this->touch($at);
    }

    /**
     * Withdraws a cancellation requested for the end of the period: the
     * contract renews again. Only a contract that is still alive can be
     * kept; one that has ended is gone (ADR-0072 §2) and a new one is
     * signed instead.
     */
    public function keepRenewing(DateTimeImmutable $at): void
    {
        if ($this->status->isFinal()) {
            throw InvalidSubscriptionTransition::becauseNotAllowed(
                $this->status->value,
                $this->status->value,
            );
        }

        $this->cancelAtPeriodEnd = false;
        $this->touch($at);
    }

    /**
     * ADR-0072 §5 — a change of plan scheduled at the end of the current
     * period. `null` withdraws the request.
     */
    public function requestPlanChange(?string $planVersionId): void
    {
        $this->assertNegotiable();

        $this->pendingPlanVersionId = $planVersionId;
        $this->version++;
    }

    /**
     * ADR-0072 §5 — the change of plan takes effect: the contract now
     * runs on another version of the plan, and the new terms are frozen
     * on it exactly like the first ones.
     */
    public function applyPlanChange(
        string $planId,
        string $planVersionId,
        int $priceMinor,
        string $currency,
        BillingInterval $interval,
        DateTimeImmutable $at,
    ): void {
        $this->assertNegotiable();

        // The contract is now ON the other plan: its code, its rights and
        // its renewal follow — not only its price.
        $this->planId = $planId;
        $this->planVersionId = $planVersionId;
        $this->priceMinor = $priceMinor;
        $this->currency = strtoupper($currency);
        $this->interval = $interval;

        // The request has been carried out: it is no longer pending.
        if ($this->pendingPlanVersionId === $planVersionId) {
            $this->pendingPlanVersionId = null;
        }

        $this->touch($at);
    }

    /**
     * The period the contract is running, or the one it is about to run.
     */
    public function attachPeriod(?string $periodId, DateTimeImmutable $at): void
    {
        $this->currentPeriodId = $periodId;
        $this->touch($at);
    }

    /**
     * ADR-0072 §2 — a contract is created with a trial: it is `trialing`,
     * and the trial has an end date.
     *
     * The trial is granted at the signature, on a contract that has not
     * started yet: a running contract cannot suddenly begin a trial.
     */
    public function beginTrial(DateTimeImmutable $trialEndsAt, DateTimeImmutable $at): void
    {
        if ($this->status !== SubscriptionStatus::Incomplete) {
            throw InvalidSubscriptionTransition::becauseNotAllowed(
                $this->status->value,
                SubscriptionStatus::Trialing->value,
            );
        }

        $this->status = SubscriptionStatus::Trialing;
        $this->trialEndsAt = $trialEndsAt;
        $this->touch($at);
    }

    /**
     * ADR-0072 §2 — the first invoice of a paying contract is issued, and
     * the contract waits for its payment.
     *
     * The state says the contract has not started yet: a running contract
     * is never sent back to "incomplete".
     */
    public function markIncomplete(DateTimeImmutable $at): void
    {
        if ($this->status !== SubscriptionStatus::Incomplete) {
            throw InvalidSubscriptionTransition::becauseNotAllowed(
                $this->status->value,
                SubscriptionStatus::Incomplete->value,
            );
        }

        $this->touch($at);
    }

    /**
     * ADR-0072 §3 — the rights follow the state, not the other way round.
     *
     * A contract grants its rights while it is `trialing` or `active`, and
     * while it is `past_due` only if the plan version grants the grace
     * (`grace_access`) — the flag lives on the plan version, so it is
     * given to us.
     */
    public function hasAccess(bool $graceAccess): bool
    {
        return $this->status->grantsAccess($graceAccess);
    }

    /**
     * ADR-0072 §2 — the transition table is the one authority.
     */
    private function assertCanTransitionTo(SubscriptionStatus $to): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw InvalidSubscriptionTransition::becauseNotAllowed($this->status->value, $to->value);
        }
    }

    /**
     * A dead contract stays dead: `canceled` and `expired` are final
     * (ADR-0072 §2), so they never negotiate new terms.
     */
    private function assertNegotiable(): void
    {
        if ($this->status->isFinal()) {
            throw InvalidSubscriptionTransition::becauseNotAllowed($this->status->value, 'a new plan version');
        }
    }

    /**
     * Every mutation moves the optimistic lock (ADR-0072 §2) and stamps
     * the row.
     */
    private function touch(DateTimeImmutable $at): void
    {
        $this->version++;
        $this->updatedAt = $at;
    }
}
