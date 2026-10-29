<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Service\SubscriptionViewBuilder;
use Core\Billing\Application\Support\SubscriptionEvents;
use Core\Billing\Contracts\ChangeSubscriptionPlan;
use Core\Billing\Contracts\ChangeSubscriptionPlanCommand;
use Core\Billing\Contracts\SubscriptionView;
use Core\Billing\Domain\Exception\InvalidSubscriptionTransition;
use Core\Billing\Domain\Exception\PlanNotFound;
use Core\Billing\Domain\Exception\SubscriptionNotFound;
use Core\Billing\Domain\Model\PeriodKind;
use Core\Billing\Domain\Model\PeriodState;
use Core\Billing\Domain\Model\PlanVersion;
use Core\Billing\Domain\Model\Subscription;
use Core\Billing\Domain\Model\SubscriptionPeriod;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use Core\Billing\Domain\Port\PlanRepository;
use Core\Billing\Domain\Port\PlanVersionRepository;
use Core\Billing\Domain\Port\SubscriptionPeriodRepository;
use Core\Billing\Domain\Port\SubscriptionRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;
use DateTimeImmutable;

/**
 * ADR-0072 §5 — `POST /v1/subscriptions/{id}/change-plan`.
 *
 * Two effects, one decision (ADR-0072 §5):
 *
 * - `period_end` (the default): the request is armed
 *   (`pending_plan_version_id`) and the **next** period carries the new
 *   version; the current period is left untouched;
 * - `immediate`: the current period is closed **now** and a new one
 *   starts with the new version — the new price, the new currency and
 *   the new interval are frozen on the contract.
 *
 * **No prorata in v1** (ADR-0071 §6, ADR-0090): an immediate change
 * starts a whole new period, and the decision is displayed and confirmed
 * by the caller.
 */
final readonly class ChangeSubscriptionPlanUseCase implements ChangeSubscriptionPlan
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private SubscriptionRepository $subscriptions,
        private SubscriptionPeriodRepository $periods,
        private PlanRepository $plans,
        private PlanVersionRepository $versions,
        private BillingSettingsRepository $settings,
        private EventOutbox $outbox,
        private SubscriptionViewBuilder $views,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function changePlan(ChangeSubscriptionPlanCommand $command): SubscriptionView
    {
        return $this->unitOfWork->transactional(function () use ($command): SubscriptionView {
            $now = $this->clock->now();

            $subscription = $this->subscriptions->findById($command->subscriptionId);

            if ($subscription === null || $subscription->projectId !== $command->projectId) {
                throw SubscriptionNotFound::becauseId($command->subscriptionId);
            }

            $plan = $this->plans->findByCode($command->projectId, $command->planCode)
                ?? throw PlanNotFound::becauseCode($command->planCode);

            if ($plan->isArchived()) {
                throw new PlanNotFound(
                    "The plan \"{$command->planCode}\" is archived and takes no new subscription (ADR-0071 §5).",
                );
            }

            $version = $this->versions->findLatestPublished($plan->id)
                ?? throw new PlanNotFound(
                    "The plan \"{$command->planCode}\" has no published version (ADR-0071 §2).",
                );

            // One live contract per customer and per plan (ADR-0072 §1): moving to a
            // plan the customer already holds would collide, and in the scheduler
            // it would break the renewal. Refused here, with a sentence.
            if ($plan->id !== $subscription->planId) {
                $held = $this->subscriptions->findLiveByCustomerAndPlan($subscription->customerId, $plan->id);

                if ($held !== null && $held->id !== $subscription->id) {
                    throw new InvalidSubscriptionTransition(
                        "The customer already holds a live contract on the plan \"{$command->planCode}\" (ADR-0072 §1).",
                    );
                }
            }

            match ($command->effective) {
                'period_end' => $this->armForPeriodEnd($subscription, $version),
                'immediate' => $this->applyNow($subscription, $version, $now),
                default => throw new InvalidSubscriptionTransition(
                    "The effectiveness \"{$command->effective}\" is unknown: expected \"period_end\" or \"immediate\" (ADR-0072 §5).",
                ),
            };

            $this->subscriptions->save($subscription);

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $command->projectId,
                'subscription.plan_changed',
                SubscriptionEvents::payload(
                    $subscription,
                    $this->views->customerRef($subscription),
                    $this->views->currentPeriod($subscription),
                ),
                $command->correlationId,
                $now,
            ));

            return $this->views->build($subscription);
        });
    }

    /**
     * ADR-0072 §5 — the change takes effect at the end of the period:
     * the request is armed and the scheduler gives the next period the
     * new version.
     */
    private function armForPeriodEnd(Subscription $subscription, PlanVersion $version): void
    {
        $subscription->requestPlanChange($version->id);
    }

    /**
     * ADR-0072 §5 — the change takes effect now: the current period is
     * closed and a new one starts with the new terms.
     */
    private function applyNow(Subscription $subscription, PlanVersion $version, DateTimeImmutable $now): void
    {
        $current = $this->periods->findCurrent($subscription->id);

        if ($current !== null) {
            if ($now <= $current->startsAt) {
                throw new InvalidSubscriptionTransition(
                    'An immediate change of plan is refused at the very instant the current period starts (ADR-0072 §5).',
                );
            }

            // The period is closed **now**: its end is pulled back to the
            // instant of the change. Without that, the new period would
            // overlap it and the exclusion constraint of ADR-0072 §1
            // would refuse the row.
            $current->endsAt = $now;
            $current->close($now);

            $this->periods->save($current);
        }

        // The new terms are frozen on the contract, exactly like the
        // first ones (ADR-0072 §5, ADR-0092 §6).
        $subscription->applyPlanChange(
            $version->planId,
            $version->id,
            $version->priceMinor,
            $version->currency,
            $version->interval,
            $now,
        );

        $zone = $this->settings->find($subscription->projectId)->zone();

        // The period that follows — the renewal the scheduler may have
        // already prepared (ADR-0072 §4) — is **reused**: it keeps its
        // rank and takes the new terms. Creating a second period at
        // that rank would break the uniqueness of
        // `(subscription_id, seq)`, and the whole transaction would be
        // rolled back: an immediate change was impossible during the
        // days that precede the deadline.
        $next = $current === null ? null : $this->periods->findBySeq($subscription->id, $current->seq + 1);

        if ($next !== null) {
            $next->startsAt = $now;
            $next->endsAt = $version->interval->endOf($now, $zone);
            $next->kind = PeriodKind::Paid;
            $next->invoiceId = null;
            $next->paidAt = null;
            $next->makeCurrent($now);

            $this->periods->save($next);

            $period = $next;
        } else {
            $period = new SubscriptionPeriod(
                id: $this->identifier->generate(),
                subscriptionId: $subscription->id,
                projectId: $subscription->projectId,
                seq: ($current->seq ?? 0) + 1,
                kind: PeriodKind::Paid,
                startsAt: $now,
                endsAt: $version->interval->endOf($now, $zone),
                state: PeriodState::Current,
                // ADR-0092 §1 — the invoice of a period belongs to Lot 11.
                invoiceId: null,
                reason: null,
                paidAt: null,
                createdAt: $now,
            );

            $this->periods->save($period);
        }

        $subscription->attachPeriod($period->id, $now);
    }
}
