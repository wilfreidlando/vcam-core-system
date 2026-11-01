<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Service\SubscriptionViewBuilder;
use Core\Billing\Contracts\GetSubscription;
use Core\Billing\Contracts\GetSubscriptionCommand;
use Core\Billing\Contracts\SubscriptionView;
use Core\Billing\Domain\Port\SubscriptionRepository;
use Core\Support\Access\AccessScope;

/**
 * ADR-0072 §7 — `GET /v1/subscriptions/{id}`: the state of a contract,
 * its current period and its next due date.
 *
 * The scope is a **required** parameter (ADR-0069 §2): a contract of
 * another project is **not found** — never told apart from one that does
 * not exist (G15).
 */
final readonly class GetSubscriptionUseCase implements GetSubscription
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private SubscriptionViewBuilder $views,
    ) {}

    public function get(GetSubscriptionCommand $command, AccessScope $scope): ?SubscriptionView
    {
        if (! $scope->allowsProject($command->projectId)) {
            return null;
        }

        $subscription = $this->subscriptions->findById($command->subscriptionId);

        if ($subscription === null || $subscription->projectId !== $command->projectId) {
            return null;
        }

        return $this->views->build($subscription);
    }
}
