<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Dto\AdvanceSubscriptionsReport;
use Core\Billing\Application\Port\SubscriptionDirectory;
use Core\Billing\Application\Port\SubscriptionNoticeLog;
use Core\Billing\Application\Service\PeriodInvoiceIssuer;
use Core\Billing\Application\Support\SubscriptionEvents;
use Core\Billing\Domain\Model\BillingSettings;
use Core\Billing\Domain\Model\PeriodKind;
use Core\Billing\Domain\Model\PeriodState;
use Core\Billing\Domain\Model\PlanVersion;
use Core\Billing\Domain\Model\Subscription;
use Core\Billing\Domain\Model\SubscriptionPeriod;
use Core\Billing\Domain\Model\SubscriptionStatus;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\PlanVersionRepository;
use Core\Billing\Domain\Port\SubscriptionPeriodRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ADR-0072 §8 — the scheduler: every contract whose next step is due is
 * advanced, every five minutes, by `billing:advance-subscriptions`.
 *
 * The steps it takes (ADR-0072 §2/§4):
 *
 * - at `ends_at − renewal_lead_days` (the lead time is a setting of the
 *   project), the **next** period is created and `subscription.renewal_due`
 *   is published — a payment made in advance settles that `upcoming`
 *   period, it never creates a second one;
 * - at `ends_at`, the current period closes and the next one becomes the
 *   one running; the contract is renewed when that period is settled, and
 *   becomes late when it is a billed period nobody paid for;
 * - a contract that is late for longer than the grace of its plan version
 *   ends;
 * - an `incomplete` contract — one whose first invoice was never paid —
 *   ends after `incomplete_expiry_hours`;
 * - a contract cancelled for the end of its period ends there.
 *
 * **It is idempotent** (ADR-0072 §8). A replay finds the next period
 * already created (`subscription_periods_seq_unique`), so it creates
 * none; the status transitions are guarded by the optimistic lock of the
 * contract (`UPDATE … WHERE status = :expected AND version = :v`), so a
 * replay applies none; and every event is published in the same
 * transaction as the state it describes, so a replay publishes none.
 *
 * The time is injected (ADR-0072 §8): the test clocks of ADR-0087
 * unroll a whole cycle in staging without waiting.
 */
final readonly class AdvanceSubscriptionsUseCase
{
    /** ADR-0072 §9 — « the trial ends in three days ». */
    private const TRIAL_WARNING_DAYS = 3;

    public function __construct(
        private UnitOfWork $unitOfWork,
        private SubscriptionDirectory $subscriptions,
        private SubscriptionPeriodRepository $periods,
        private PlanVersionRepository $versions,
        private BillingSettingsRepository $settings,
        private CustomerRepository $customers,
        private EventOutbox $outbox,
        private PeriodInvoiceIssuer $invoices,
        private Identifier $identifier,
        private Clock $clock,
        private LoggerInterface $logger,
        private SubscriptionNoticeLog $notices,
    ) {}

    /**
     * One pass over the contracts whose next step may be due.
     */
    public function advance(int $limit = 500): AdvanceSubscriptionsReport
    {
        $now = $this->clock->now();
        $report = new AdvanceSubscriptionsReport;

        $candidates = $this->subscriptions->findDueForAdvance($now, $limit);

        foreach ($candidates as $candidate) {
            $report->examined++;

            // One transaction per contract: a contract that fails does
            // not take the others down with it.
            try {
                $this->unitOfWork->transactional(function () use ($candidate, $now, $report): void {
                    $this->advanceOne($candidate, $now, $report);
                });
            } catch (Throwable $exception) {
                // A contract that cannot be advanced — a race with
                // another pass, a setting that no longer applies — is
                // counted and logged; the pass continues with the next
                // one. The scheduler must never stop halfway: the
                // contracts it did not reach are the ones that would
                // then never advance.
                $report->failed++;

                $this->logger->error('billing.subscription.advance_failed', [
                    'subscription_id' => $candidate->id,
                    'project_id' => $candidate->projectId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $report;
    }

    private function advanceOne(Subscription $subscription, DateTimeImmutable $now, AdvanceSubscriptionsReport $report): void
    {
        $settings = $this->settings->find($subscription->projectId);

        $current = $this->periods->findCurrent($subscription->id);

        // The period that follows the one running is the one the
        // contract rolls over to. It is looked up by its rank, not by its
        // state: a complimentary period granted by the operator and a
        // renewal prepared by the scheduler both sit in that slot
        // (ADR-0072 §4, ADR-0072 §6).
        $upcoming = $current === null ? null : $this->periods->findBySeq($subscription->id, $current->seq + 1);

        match ($subscription->status) {
            SubscriptionStatus::Incomplete => $this->expireIncomplete($subscription, $current, $settings, $now, $report),
            SubscriptionStatus::Trialing,
            SubscriptionStatus::Active => $this->advancePeriods($subscription, $current, $upcoming, $settings, $now, $report),
            SubscriptionStatus::PastDue => $this->expirePastDue($subscription, $current, $now, $report),
            // A paused contract waits for the operator, and a dead one is
            // dead for good (ADR-0072 §2).
            SubscriptionStatus::Paused,
            SubscriptionStatus::Canceled,
            SubscriptionStatus::Expired => null,
        };
    }

    /**
     * ADR-0072 §4 — the renewal machinery: the next period is prepared
     * before the deadline, and the periods roll over at the deadline.
     */
    private function advancePeriods(
        Subscription $subscription,
        ?SubscriptionPeriod $current,
        ?SubscriptionPeriod $upcoming,
        BillingSettings $settings,
        DateTimeImmutable $now,
        AdvanceSubscriptionsReport $report,
    ): void {
        if ($current === null) {
            return;
        }

        // ADR-0072 §9 — three days before the trial ends, the project is told,
        // once: it is the moment to ask the customer to pay.
        if ($subscription->status === SubscriptionStatus::Trialing
            && $subscription->trialEndsAt !== null
            && $now < $subscription->trialEndsAt
            && $now >= $subscription->trialEndsAt->sub(self::days(self::TRIAL_WARNING_DAYS))
            && $this->notices->firstTime($subscription->id, 'trial_will_end')) {
            $this->publish($subscription, 'subscription.trial_will_end', $current, $now);
        }

        // ADR-0072 §2 — a cancellation at the end of the period: the
        // contract lives until the deadline, and ends there.
        if ($subscription->cancelAtPeriodEnd && $now >= $current->endsAt) {
            $current->close($now);
            $this->periods->save($current);

            $subscription->cancel($now, false);
            $this->subscriptions->save($subscription);

            $this->publish($subscription, 'subscription.canceled', $current, $now);
            $report->canceled++;

            return;
        }

        // ADR-0072 §4 — the next period is created before the deadline,
        // so that a payment made in advance settles it.
        if ($upcoming === null && $now >= $current->endsAt->sub(self::days($settings->renewalLeadDays))) {
            $upcoming = $this->prepareNextPeriod($subscription, $current, $settings->zone(), $now);

            $this->publish($subscription, 'subscription.renewal_due', $current, $now);
            $report->renewalsPrepared++;
        }

        if ($upcoming === null || $now < $current->endsAt) {
            return;
        }

        // ADR-0072 §4 — at the end of the period, the next one becomes
        // the one running.
        $current->close($now);
        $this->periods->save($current);

        $upcoming->makeCurrent($now);
        $this->periods->save($upcoming);

        $subscription->attachPeriod($upcoming->id, $now);
        $this->subscriptions->save($subscription);

        $version = $this->versions->findById($subscription->planVersionId);

        // A trial and a complimentary period are free: they never make
        // a contract late. A billed period nobody paid for does.
        if ($upcoming->kind === PeriodKind::Paid && ! $upcoming->isPaid()) {
            $this->markUnpaid($subscription, $version, $upcoming, $now, $report);

            return;
        }

        if ($subscription->status === SubscriptionStatus::Trialing) {
            // ADR-0072 §2 — the trial is over and the first billed
            // period is settled: the contract runs.
            $subscription->activate($now);
            $this->subscriptions->save($subscription);

            $this->publish($subscription, 'subscription.activated', $upcoming, $now);
            $report->activated++;

            return;
        }

        $this->publish($subscription, 'subscription.renewed', $upcoming, $now);
        $report->renewed++;
    }

    /**
     * ADR-0072 §2 — a renewal nobody paid for: the contract is late,
     * and the grace of its plan version decides whether the rights
     * survive and how long the contract survives.
     */
    private function markUnpaid(
        Subscription $subscription,
        ?PlanVersion $version,
        SubscriptionPeriod $currentPeriod,
        DateTimeImmutable $now,
        AdvanceSubscriptionsReport $report,
    ): void {
        $graceDays = $version->graceDays ?? 0;

        if ($graceDays > 0) {
            $subscription->markPastDue($now);
            $this->subscriptions->save($subscription);

            $this->publish($subscription, 'subscription.past_due', $currentPeriod, $now);
            $report->pastDue++;

            return;
        }

        // No grace: the contract ends at once. ADR-0072 §2 does not allow
        // `active → expired` directly, so the contract goes through
        // `past_due` — whose grace is zero long — and ends there.
        if ($subscription->status === SubscriptionStatus::Active) {
            $subscription->markPastDue($now);
            $this->subscriptions->save($subscription);
        }

        $subscription->expire($now);
        $this->subscriptions->save($subscription);

        $this->publish($subscription, 'subscription.expired', $currentPeriod, $now);
        $report->expired++;
    }

    /**
     * ADR-0072 §2 — an incomplete contract — one whose first invoice
     * was never paid — ends after `incomplete_expiry_hours`.
     */
    private function expireIncomplete(
        Subscription $subscription,
        ?SubscriptionPeriod $current,
        BillingSettings $settings,
        DateTimeImmutable $now,
        AdvanceSubscriptionsReport $report,
    ): void {
        $start = $current->startsAt ?? $subscription->createdAt;
        $expiry = $start->add(self::hours($settings->incompleteExpiryHours));

        if ($now < $expiry) {
            return;
        }

        $subscription->expire($now);
        $this->subscriptions->save($subscription);

        $this->publish($subscription, 'subscription.expired', $current, $now);
        $report->expired++;
    }

    /**
     * ADR-0072 §2 — the grace ran out without a payment: the contract
     * is over.
     *
     * The grace runs from the moment the contract became late, which is
     * the moment the unpaid period started.
     */
    private function expirePastDue(
        Subscription $subscription,
        ?SubscriptionPeriod $current,
        DateTimeImmutable $now,
        AdvanceSubscriptionsReport $report,
    ): void {
        if ($current === null) {
            return;
        }

        $version = $this->versions->findById($subscription->planVersionId);
        $graceDays = $version->graceDays ?? 0;

        $graceEnd = $current->startsAt->add(self::days($graceDays));

        if ($now < $graceEnd) {
            return;
        }

        $subscription->expire($now);
        $this->subscriptions->save($subscription);

        $this->publish($subscription, 'subscription.expired', $current, $now);
        $report->expired++;
    }

    /**
     * ADR-0072 §4 — the next period, created before its deadline.
     *
     * ADR-0072 §5 — a change of plan scheduled at the end of the period
     * takes effect here: the next period carries the new version, and
     * the new terms are frozen on the contract.
     */
    private function prepareNextPeriod(
        Subscription $subscription,
        SubscriptionPeriod $current,
        DateTimeZone $zone,
        DateTimeImmutable $now,
    ): SubscriptionPeriod {
        $pending = $subscription->pendingPlanVersionId === null
            ? null
            : $this->versions->findById($subscription->pendingPlanVersionId);

        if ($pending !== null) {
            $subscription->applyPlanChange(
                $pending->planId,
                $pending->id,
                $pending->priceMinor,
                $pending->currency,
                $pending->interval,
                $now,
            );

            $this->subscriptions->save($subscription);
        }

        $period = new SubscriptionPeriod(
            id: $this->identifier->generate(),
            subscriptionId: $subscription->id,
            projectId: $subscription->projectId,
            seq: $current->seq + 1,
            kind: PeriodKind::Paid,
            startsAt: $current->endsAt,
            endsAt: $this->nextPeriodEnd($subscription, $current, $zone),
            state: PeriodState::Upcoming,
            // ADR-0073 §1 — a **billed** period carries its invoice.
            invoiceId: null,
            reason: null,
            paidAt: null,
            createdAt: $now,
        );

        $this->periods->save($period);

        // ADR-0094 §6 — the invoice of the renewal is created **and
        // issued** in the very transaction that prepares the period: the
        // observer sees an open invoice, and the number is attributed at
        // the issuance.
        $period->invoiceId = $this->invoices->issue($subscription, $period);

        $this->periods->save($period);

        return $period;
    }

    /**
     * ADR-0071 §4 — the arithmetic of the periods is computed from the
     * **anchor**, so that a contract anchored on the 31st gives 28/29
     * February then 31 March again, and the anchor never drifts.
     *
     * The anchor only describes the current period as long as the
     * current period IS the one the anchor produces: a trial, a
     * complimentary period, a pause or a change of interval break that
     * alignment — and then the period that runs is the one the current
     * period leads to.
     */
    private function nextPeriodEnd(
        Subscription $subscription,
        SubscriptionPeriod $current,
        DateTimeZone $zone,
    ): DateTimeImmutable {
        $interval = $subscription->interval;

        $anchorEnd = $interval->endOf($subscription->anchorAt, $zone, $current->seq);

        if ($current->kind === PeriodKind::Paid && $current->endsAt->getTimestamp() === $anchorEnd->getTimestamp()) {
            return $interval->endOf($subscription->anchorAt, $zone, $current->seq + 1);
        }

        return $interval->endOf($current->endsAt, $zone, 1);
    }

    /**
     * ADR-0072 §9 — the public trace of the step, published in the same
     * transaction as the state it describes (G8).
     */
    private function publish(
        Subscription $subscription,
        string $type,
        ?SubscriptionPeriod $currentPeriod,
        DateTimeImmutable $now,
    ): void {
        $this->outbox->append(new DomainEvent(
            $this->identifier->generate(),
            $subscription->projectId,
            $type,
            SubscriptionEvents::payload(
                $subscription,
                $this->customers->findById($subscription->customerId)->externalRef ?? '',
                $currentPeriod,
            ),
            null,
            $now,
        ));
    }

    private static function days(int $count): DateInterval
    {
        return new DateInterval('P'.max(0, $count).'D');
    }

    private static function hours(int $count): DateInterval
    {
        return new DateInterval('PT'.max(0, $count).'H');
    }
}
