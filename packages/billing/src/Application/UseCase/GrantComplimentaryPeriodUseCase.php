<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Service\SubscriptionViewBuilder;
use Core\Billing\Application\Support\SubscriptionEvents;
use Core\Billing\Contracts\GrantComplimentaryPeriod;
use Core\Billing\Contracts\GrantComplimentaryPeriodCommand;
use Core\Billing\Contracts\SubscriptionView;
use Core\Billing\Domain\Exception\InvalidSubscriptionTransition;
use Core\Billing\Domain\Exception\SubscriptionNotFound;
use Core\Billing\Domain\Model\BillingInterval;
use Core\Billing\Domain\Model\BillingIntervalUnit;
use Core\Billing\Domain\Model\PeriodKind;
use Core\Billing\Domain\Model\PeriodState;
use Core\Billing\Domain\Model\SubscriptionPeriod;
use Core\Billing\Domain\Model\SubscriptionStatus;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use Core\Billing\Domain\Port\SubscriptionPeriodRepository;
use Core\Billing\Domain\Port\SubscriptionRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0072 §6 — the operator's gesture: a period of N days **without
 * invoice**, with a mandatory reason.
 *
 * A complimentary period is an explicit, visible and auditable act —
 * never a fictitious payment (ADR-0072 §Alternatives écartées). It
 * starts when the current period ends: the customer keeps what it paid
 * for, and the free period extends the contract after it.
 *
 * ADR-0092 §1 — this is the **only public path to `active`** in Lot 10:
 * a paying contract cannot become active by a payment before the
 * invoicing lands.
 */
final readonly class GrantComplimentaryPeriodUseCase implements GrantComplimentaryPeriod
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private SubscriptionRepository $subscriptions,
        private SubscriptionPeriodRepository $periods,
        private BillingSettingsRepository $settings,
        private EventOutbox $outbox,
        private SubscriptionViewBuilder $views,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function grant(GrantComplimentaryPeriodCommand $command): SubscriptionView
    {
        return $this->unitOfWork->transactional(function () use ($command): SubscriptionView {
            $now = $this->clock->now();

            $subscription = $this->subscriptions->findById($command->subscriptionId);

            if ($subscription === null || $subscription->projectId !== $command->projectId) {
                throw SubscriptionNotFound::becauseId($command->subscriptionId);
            }

            // ADR-0072 §6 — an operator act is an audited act: it is
            // filed with a reason, never without one.
            if (trim($command->reason) === '') {
                throw new InvalidSubscriptionTransition(
                    'A complimentary period requires a reason: an operator act is an audited act (ADR-0072 §6).',
                );
            }

            if ($command->days < 1) {
                throw new InvalidSubscriptionTransition(
                    'A complimentary period must last at least one day (ADR-0072 §6).',
                );
            }

            $zone = $this->settings->find($subscription->projectId)->zone();

            $current = $this->periods->findCurrent($subscription->id);
            $next = $current === null ? null : $this->periods->findBySeq($subscription->id, $current->seq + 1);

            if ($next !== null && ! $next->isPaid()) {
                // The free period takes the place of the period that was
                // prepared (ADR-0072 §4): "the month offered" IS the next
                // period, free of charge. A second period would overlap
                // it, and the exclusion constraint of ADR-0072 §1 would
                // refuse the row.
                $next->kind = PeriodKind::Complimentary;
                $next->reason = $command->reason;
                $next->endsAt = BillingInterval::of(BillingIntervalUnit::Day, $command->days)
                    ->endOf($next->startsAt, $zone);

                $this->periods->save($next);
            } else {
                // The next period is already settled — a payment made in
                // advance (ADR-0072 §4) — and a payment is a fact
                // (ADR-0092 §2): it is not touched. The free period then
                // follows it, at the end of the timeline the contract
                // holds.
                if ($next !== null) {
                    $startsAt = $next->endsAt;
                    $seq = $next->seq + 1;
                } elseif ($current !== null) {
                    $startsAt = $current->endsAt;
                    $seq = $current->seq + 1;
                } else {
                    $startsAt = $now;
                    $seq = 1;
                }

                $period = new SubscriptionPeriod(
                    id: $this->identifier->generate(),
                    subscriptionId: $subscription->id,
                    projectId: $subscription->projectId,
                    seq: $seq,
                    kind: PeriodKind::Complimentary,
                    startsAt: $startsAt,
                    endsAt: BillingInterval::of(BillingIntervalUnit::Day, $command->days)->endOf($startsAt, $zone),
                    state: PeriodState::Upcoming,
                    // A complimentary period is free: it carries no
                    // invoice (ADR-0072 §6).
                    invoiceId: null,
                    reason: $command->reason,
                    paidAt: null,
                    createdAt: $now,
                );

                $this->periods->save($period);
            }

            // ADR-0092 §1 — the only public path to `active` in this lot.
            if ($subscription->status !== SubscriptionStatus::Active) {
                $subscription->activate($now);
            }

            $this->subscriptions->save($subscription);

            // The grant always ends with a contract that runs; the event
            // is the public trace of the operator's act (ADR-0092 §3) —
            // the catalogue of ADR-0072 §9 has no dedicated type for a
            // granted period.
            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $command->projectId,
                'subscription.activated',
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
}
