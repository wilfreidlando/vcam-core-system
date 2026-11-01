<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Service\SubscriptionViewBuilder;
use Core\Billing\Application\Support\SubscriptionEvents;
use Core\Billing\Contracts\ResumeSubscription;
use Core\Billing\Contracts\ResumeSubscriptionCommand;
use Core\Billing\Contracts\SubscriptionView;
use Core\Billing\Domain\Exception\InvalidSubscriptionTransition;
use Core\Billing\Domain\Exception\SubscriptionNotFound;
use Core\Billing\Domain\Port\SubscriptionPeriodRepository;
use Core\Billing\Domain\Port\SubscriptionRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0072 §6 — the operator's gesture: the contract runs again.
 *
 * ADR-0072 §2 — the current period is **prolonged by the duration of the
 * pause**: the customer does not lose the days the contract spent
 * suspended. The contract alone does not know where its current period
 * ends, so the prolongation is the use case's job.
 */
final readonly class ResumeSubscriptionUseCase implements ResumeSubscription
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

    public function resume(ResumeSubscriptionCommand $command): SubscriptionView
    {
        return $this->unitOfWork->transactional(function () use ($command): SubscriptionView {
            $now = $this->clock->now();

            $subscription = $this->subscriptions->findById($command->subscriptionId);

            if ($subscription === null || $subscription->projectId !== $command->projectId) {
                throw SubscriptionNotFound::becauseId($command->subscriptionId);
            }

            $pausedAt = $subscription->pausedAt
                ?? throw new InvalidSubscriptionTransition(
                    'The contract is not paused: there is nothing to resume (ADR-0072 §2).',
                );

            $current = $this->periods->findCurrent($subscription->id);

            if ($current !== null) {
                $pause = $pausedAt->diff($now);

                // The pause is added to the period: no day is lost.
                $current->endsAt = $current->endsAt->add($pause);

                // The period that follows moves with it: the periods of a
                // contract never overlap (the exclusion constraint of
                // ADR-0072 §1), and the one that was already prepared
                // keeps its length.
                //
                // **The next period is written first.** The exclusion
                // constraint is checked at every statement (it is not
                // deferrable): extending the current period before the
                // next one has moved away would make the two overlap,
                // and the database would refuse the whole transaction —
                // the contract would stay paused and the customer would
                // lose the days of the pause.
                $next = $this->periods->findBySeq($subscription->id, $current->seq + 1);

                if ($next !== null) {
                    $next->startsAt = $next->startsAt->add($pause);
                    $next->endsAt = $next->endsAt->add($pause);

                    $this->periods->save($next);
                }

                $this->periods->save($current);
            }

            $subscription->resume($now);

            $this->subscriptions->save($subscription);

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $command->projectId,
                'subscription.resumed',
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
