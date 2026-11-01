<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Service\SubscriptionViewBuilder;
use Core\Billing\Application\Support\SubscriptionEvents;
use Core\Billing\Contracts\CancelSubscription;
use Core\Billing\Contracts\CancelSubscriptionCommand;
use Core\Billing\Contracts\SubscriptionView;
use Core\Billing\Domain\Exception\SubscriptionNotFound;
use Core\Billing\Domain\Port\SubscriptionRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0072 §7 — `POST /v1/subscriptions/{id}/cancel`.
 *
 * Two gestures, two meanings (ADR-0072 §2):
 *
 * - `atPeriodEnd = true`: the contract keeps its rights until the end of
 *   its period; only the flag is armed, and the contract ends on the
 *   deadline — the scheduler says so then;
 * - `atPeriodEnd = false`: the contract ends at once, and what is left of
 *   the period is lost.
 *
 * `canceled` and `expired` are final (ADR-0072 §2): a cancelled contract
 * never comes back — a new one is signed instead.
 */
final readonly class CancelSubscriptionUseCase implements CancelSubscription
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private SubscriptionRepository $subscriptions,
        private EventOutbox $outbox,
        private SubscriptionViewBuilder $views,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function cancel(CancelSubscriptionCommand $command): SubscriptionView
    {
        return $this->unitOfWork->transactional(function () use ($command): SubscriptionView {
            $now = $this->clock->now();

            $subscription = $this->subscriptions->findById($command->subscriptionId);

            if ($subscription === null || $subscription->projectId !== $command->projectId) {
                throw SubscriptionNotFound::becauseId($command->subscriptionId);
            }

            $subscription->cancel($now, $command->atPeriodEnd);

            $this->subscriptions->save($subscription);

            // The event is the public trace of the gesture (ADR-0092 §3).
            // For a cancellation at the end of the period it announces the
            // **request** — the status it carries is still the one the
            // contract has — and the scheduler publishes it again on the
            // day the contract really ends.
            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $command->projectId,
                'subscription.canceled',
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
