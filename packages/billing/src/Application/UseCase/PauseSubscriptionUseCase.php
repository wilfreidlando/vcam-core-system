<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Service\SubscriptionViewBuilder;
use Core\Billing\Application\Support\SubscriptionEvents;
use Core\Billing\Contracts\PauseSubscription;
use Core\Billing\Contracts\PauseSubscriptionCommand;
use Core\Billing\Contracts\SubscriptionView;
use Core\Billing\Domain\Exception\SubscriptionNotFound;
use Core\Billing\Domain\Port\SubscriptionRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0072 §6 — the operator's gesture: the contract is paused.
 *
 * A pause is an **audited** act and it is filed with a reason, never
 * without one: the domain refuses a blank reason
 * (`InvalidSubscriptionTransition::becausePauseWithoutReason()`).
 *
 * The contract grants no rights while it is paused (ADR-0072 §3).
 */
final readonly class PauseSubscriptionUseCase implements PauseSubscription
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private SubscriptionRepository $subscriptions,
        private EventOutbox $outbox,
        private SubscriptionViewBuilder $views,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function pause(PauseSubscriptionCommand $command): SubscriptionView
    {
        return $this->unitOfWork->transactional(function () use ($command): SubscriptionView {
            $now = $this->clock->now();

            $subscription = $this->subscriptions->findById($command->subscriptionId);

            if ($subscription === null || $subscription->projectId !== $command->projectId) {
                throw SubscriptionNotFound::becauseId($command->subscriptionId);
            }

            // ADR-0072 §2 — the state machine is the one authority: a
            // contract that may not be paused says so, and a pause
            // without a reason is refused.
            $subscription->pause($now, $command->reason);

            $this->subscriptions->save($subscription);

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $command->projectId,
                'subscription.paused',
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
