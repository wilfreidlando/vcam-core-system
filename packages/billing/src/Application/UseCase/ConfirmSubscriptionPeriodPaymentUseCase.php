<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Service\SubscriptionViewBuilder;
use Core\Billing\Application\Support\SubscriptionEvents;
use Core\Billing\Contracts\ConfirmSubscriptionPeriodPayment;
use Core\Billing\Contracts\ConfirmSubscriptionPeriodPaymentCommand;
use Core\Billing\Contracts\SubscriptionView;
use Core\Billing\Domain\Exception\InvalidSubscriptionTransition;
use Core\Billing\Domain\Exception\SubscriptionNotFound;
use Core\Billing\Domain\Model\PeriodKind;
use Core\Billing\Domain\Model\SubscriptionStatus;
use Core\Billing\Domain\Port\SubscriptionPeriodRepository;
use Core\Billing\Domain\Port\SubscriptionRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0092 §2 — the confirmation that the invoice of a period was paid.
 *
 * `subscription_periods.paid_at` is the **single truth** on whether the
 * period is settled; this is the only use case that writes it, so a
 * period cannot be "paid" by one path and "unpaid" by another.
 *
 * **It moves no money.** No ledger entry, no wallet, no collection: the
 * act is a *declaration* — the console declares that a period has been
 * settled. The money is Lot 11's business (ADR-0092 §2, ADR-0073).
 *
 * The reference of the payment is kept on the period, in the only
 * free-text field a period carries; it is the operator's own reference,
 * never a credential.
 */
final readonly class ConfirmSubscriptionPeriodPaymentUseCase implements ConfirmSubscriptionPeriodPayment
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private SubscriptionRepository $subscriptions,
        private SubscriptionPeriodRepository $periods,
        private EventOutbox $outbox,
        private SubscriptionViewBuilder $views,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function confirm(ConfirmSubscriptionPeriodPaymentCommand $command): SubscriptionView
    {
        return $this->unitOfWork->transactional(function () use ($command): SubscriptionView {
            $now = $this->clock->now();

            $subscription = $this->subscriptions->findById($command->subscriptionId);

            if ($subscription === null || $subscription->projectId !== $command->projectId) {
                throw SubscriptionNotFound::becauseId($command->subscriptionId);
            }

            $period = $this->periods->findBySeq($subscription->id, $command->seq)
                ?? throw new InvalidSubscriptionTransition(
                    "The contract has no period {$command->seq} (ADR-0072 §1).",
                );

            // ADR-0072 §2 — the declaration settles **the period the
            // contract is running**: the one that is billed and unpaid.
            // Declaring an older period (the trial, say) would release a
            // contract whose current period is still unpaid — the
            // customer would be served for nothing.
            $current = $this->periods->findCurrent($subscription->id);

            if ($current === null || $current->seq !== $period->seq) {
                throw new InvalidSubscriptionTransition(
                    'Only the running period of a contract can be declared settled (ADR-0072 §2).',
                );
            }

            // A trial and a complimentary period are **free**
            // (ADR-0072 §1, ADR-0072 §6): they are not billed, and the
            // motive of a complimentary period is the operator's — it is
            // never overwritten by a payment reference.
            if ($period->kind !== PeriodKind::Paid) {
                throw new InvalidSubscriptionTransition(
                    "The period {$command->seq} of this contract is free: it is not billed (ADR-0072 §1).",
                );
            }

            if ($period->isPaid()) {
                throw new InvalidSubscriptionTransition(
                    "The period {$command->seq} of this contract is already settled (ADR-0092 §2).",
                );
            }

            $period->markPaid($now);

            if ($command->reference !== null && trim($command->reference) !== '') {
                $period->reason = trim($command->reference);
            }

            $this->periods->save($period);

            // ADR-0072 §2 — a settled period releases the contract: it
            // leaves `incomplete`, `trialing` or `past_due` for `active`.
            if (in_array($subscription->status, [
                SubscriptionStatus::Incomplete,
                SubscriptionStatus::Trialing,
                SubscriptionStatus::PastDue,
            ], true)) {
                $subscription->activate($now);

                $this->subscriptions->save($subscription);

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
            }

            return $this->views->build($subscription);
        });
    }
}
