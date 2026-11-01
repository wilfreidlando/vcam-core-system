<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Service\SubscriptionViewBuilder;
use Core\Billing\Application\Support\SubscriptionEvents;
use Core\Billing\Contracts\KeepSubscriptionRenewing;
use Core\Billing\Contracts\KeepSubscriptionRenewingCommand;
use Core\Billing\Contracts\SubscriptionView;
use Core\Billing\Domain\Exception\InvalidSubscriptionTransition;
use Core\Billing\Domain\Exception\SubscriptionNotFound;
use Core\Billing\Domain\Port\SubscriptionRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * A customer changes its mind before the end of the period: the contract
 * that was going to stop renews again. Idempotent — keeping a contract that
 * is not ending changes nothing and publishes nothing.
 */
final readonly class KeepSubscriptionRenewingUseCase implements KeepSubscriptionRenewing
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private SubscriptionRepository $subscriptions,
        private EventOutbox $outbox,
        private SubscriptionViewBuilder $views,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function keep(KeepSubscriptionRenewingCommand $command): SubscriptionView
    {
        return $this->unitOfWork->transactional(function () use ($command): SubscriptionView {
            $now = $this->clock->now();
            $subscription = $this->subscriptions->findById($command->subscriptionId);

            if ($subscription === null || $subscription->projectId !== $command->projectId) {
                throw SubscriptionNotFound::becauseId($command->subscriptionId);
            }

            // An ended contract is gone (ADR-0072 §2): saying « kept » would be a lie.
            if ($subscription->status->isFinal()) {
                throw new InvalidSubscriptionTransition(
                    "The contract is {$subscription->status->value}: it cannot be kept — a new one is signed instead.",
                );
            }

            if (! $subscription->cancelAtPeriodEnd) {
                return $this->views->build($subscription);
            }

            $subscription->keepRenewing($now);
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
