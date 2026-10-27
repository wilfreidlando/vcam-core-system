<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Service\SubscriptionViewBuilder;
use Core\Billing\Application\Support\SubscriptionEvents;
use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\CreateSubscriptionCommand;
use Core\Billing\Contracts\SubscriptionView;
use Core\Billing\Domain\Exception\CustomerNotFound;
use Core\Billing\Domain\Exception\InvalidSubscriptionStart;
use Core\Billing\Domain\Exception\InvalidSubscriptionTransition;
use Core\Billing\Domain\Exception\PlanNotFound;
use Core\Billing\Domain\Model\BillingInterval;
use Core\Billing\Domain\Model\BillingIntervalUnit;
use Core\Billing\Domain\Model\PeriodKind;
use Core\Billing\Domain\Model\PeriodState;
use Core\Billing\Domain\Model\Subscription;
use Core\Billing\Domain\Model\SubscriptionPeriod;
use Core\Billing\Domain\Model\SubscriptionStatus;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use Core\Billing\Domain\Port\CustomerRepository;
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
use Exception;

/**
 * ADR-0072 §7 — `POST /v1/subscriptions`: the contract a customer signs
 * with a plan.
 *
 * The contract is born **trialing** when a trial is granted, **incomplete**
 * otherwise: in Lot 10 a paying contract waits for its invoice, and the
 * invoice belongs to Lot 11 (ADR-0092 §1). The first period is therefore
 * created without one — `invoice_id` stays null.
 *
 * The dates are computed in the **timezone of the project** and stored in
 * UTC (ADR-0071 §4); the arithmetic itself belongs to `BillingInterval`,
 * which never overflows a month.
 *
 * **The idempotency key.** The command carries the `Idempotency-Key` the
 * route already requires (G7). What the key protects in Lot 10 is the
 * *contract itself*: a replay of the same request finds the live contract
 * the customer already holds for that plan (`subscriptions_live_unique`)
 * and is refused with `InvalidSubscriptionTransition` instead of signing a
 * second one. A replay that carries another customer or another plan is
 * another contract and is signed as such — the Core keeps no registry of
 * the keys (the `subscriptions` table has no column for them, and
 * ADR-0092 §4 keeps the schema minimal).
 */
final readonly class CreateSubscriptionUseCase implements CreateSubscription
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private CustomerRepository $customers,
        private PlanRepository $plans,
        private PlanVersionRepository $versions,
        private SubscriptionRepository $subscriptions,
        private SubscriptionPeriodRepository $periods,
        private BillingSettingsRepository $settings,
        private EventOutbox $outbox,
        private SubscriptionViewBuilder $views,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function create(CreateSubscriptionCommand $command): SubscriptionView
    {
        return $this->unitOfWork->transactional(function () use ($command): SubscriptionView {
            $now = $this->clock->now();

            $customer = $this->customers->findByExternalRef($command->projectId, $command->customerRef)
                ?? throw CustomerNotFound::becauseExternalRef($command->customerRef);

            $plan = $this->plans->findByCode($command->projectId, $command->planCode)
                ?? throw PlanNotFound::becauseCode($command->planCode);

            // ADR-0071 §5 — an archived plan takes no new subscription.
            if ($plan->isArchived()) {
                throw new PlanNotFound(
                    "The plan \"{$command->planCode}\" is archived and takes no new subscription (ADR-0071 §5).",
                );
            }

            // ADR-0071 §2 — a contract is signed on the latest published
            // version; a plan without one is not proposed to anyone.
            $version = $this->versions->findLatestPublished($plan->id)
                ?? throw new PlanNotFound(
                    "The plan \"{$command->planCode}\" has no published version (ADR-0071 §2).",
                );

            // ADR-0072 §1 — one live contract per customer and per plan.
            $live = $this->subscriptions->findLiveByCustomerAndPlan($customer->id, $plan->id);

            if ($live !== null) {
                throw new InvalidSubscriptionTransition(
                    "This customer already holds a live contract for the plan \"{$command->planCode}\" (ADR-0072 §1).",
                );
            }

            $settings = $this->settings->find($command->projectId);
            $zone = $settings->zone();

            $anchor = $command->startAt === null ? $now : self::parseInstant($command->startAt);

            // The trial of the plan version, overridden by the command.
            $trialDays = $command->trialDays ?? $version->trialDays;

            $subscriptionId = $this->identifier->generate();

            if ($trialDays > 0) {
                $kind = PeriodKind::Trial;
                $endsAt = BillingInterval::of(BillingIntervalUnit::Day, $trialDays)->endOf($anchor, $zone);
            } else {
                $kind = PeriodKind::Paid;
                $endsAt = $version->interval->endOf($anchor, $zone);
            }

            $period = new SubscriptionPeriod(
                id: $this->identifier->generate(),
                subscriptionId: $subscriptionId,
                projectId: $command->projectId,
                seq: 1,
                kind: $kind,
                startsAt: $anchor,
                endsAt: $endsAt,
                state: PeriodState::Current,
                // ADR-0092 §1 — the invoice of a period belongs to Lot 11.
                invoiceId: null,
                reason: null,
                paidAt: null,
                createdAt: $now,
            );

            $subscription = new Subscription(
                id: $subscriptionId,
                projectId: $command->projectId,
                customerId: $customer->id,
                planId: $plan->id,
                planVersionId: $version->id,
                status: SubscriptionStatus::Incomplete,
                // ADR-0092 §6 — the price, the currency and the interval
                // are frozen on the contract, from the version it was
                // signed on.
                priceMinor: $version->priceMinor,
                currency: $version->currency,
                interval: $version->interval,
                anchorAt: $anchor,
                currentPeriodId: $period->id,
                trialEndsAt: null,
                cancelAtPeriodEnd: false,
                pendingPlanVersionId: null,
                pausedAt: null,
                canceledAt: null,
                endedAt: null,
                version: 1,
                createdAt: $now,
                updatedAt: $now,
            );

            if ($trialDays > 0) {
                // ADR-0072 §2 — a contract created with a trial is
                // `trialing`, and the trial has an end date.
                $subscription->beginTrial($endsAt, $now);
            }

            $this->periods->save($period);
            $this->subscriptions->save($subscription);

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $command->projectId,
                'subscription.created',
                SubscriptionEvents::payload($subscription, $customer->externalRef, $period),
                $command->correlationId,
                $now,
            ));

            return $this->views->build($subscription);
        });
    }

    /**
     * The anchor of the contract — an ISO-8601 instant, refused when it
     * is not one.
     */
    private static function parseInstant(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            throw InvalidSubscriptionStart::becauseUnparsable($value);
        }
    }
}
