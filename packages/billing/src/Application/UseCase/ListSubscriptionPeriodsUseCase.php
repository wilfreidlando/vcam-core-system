<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\SubscriptionViews;
use Core\Billing\Contracts\ListSubscriptionPeriods;
use Core\Billing\Contracts\ListSubscriptionPeriodsCommand;
use Core\Billing\Contracts\SubscriptionPeriodPage;
use Core\Billing\Contracts\SubscriptionPeriodView;
use Core\Billing\Domain\Model\SubscriptionPeriod;
use Core\Billing\Domain\Port\SubscriptionPeriodRepository;
use Core\Billing\Domain\Port\SubscriptionRepository;
use Core\Support\Access\AccessScope;

/**
 * ADR-0072 §1 — the periods of one contract.
 *
 * The periods of a contract never overlap (the exclusion constraint,
 * ADR-0072 §1) and there is one per renewal: they are read whole, not
 * paginated.
 *
 * The scope is a **required** parameter (ADR-0069 §2): a contract of
 * another project yields an empty page, never another tenant's history.
 */
final readonly class ListSubscriptionPeriodsUseCase implements ListSubscriptionPeriods
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private SubscriptionPeriodRepository $periods,
    ) {}

    public function list(ListSubscriptionPeriodsCommand $command, AccessScope $scope): SubscriptionPeriodPage
    {
        if (! $scope->allowsProject($command->projectId)) {
            return new SubscriptionPeriodPage([]);
        }

        $subscription = $this->subscriptions->findById($command->subscriptionId);

        if ($subscription === null || $subscription->projectId !== $command->projectId) {
            return new SubscriptionPeriodPage([]);
        }

        $periods = $this->periods->listBySubscription($subscription->id);

        $items = array_map(
            static fn (SubscriptionPeriod $period): SubscriptionPeriodView => SubscriptionViews::period($period),
            $periods,
        );

        return new SubscriptionPeriodPage($items);
    }
}
