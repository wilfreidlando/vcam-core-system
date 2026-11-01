<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\SubscriptionDirectory;
use Core\Billing\Application\Service\SubscriptionViewBuilder;
use Core\Billing\Contracts\ListSubscriptions;
use Core\Billing\Contracts\ListSubscriptionsCommand;
use Core\Billing\Contracts\SubscriptionPage;
use Core\Billing\Contracts\SubscriptionView;
use Core\Billing\Domain\Model\Subscription;
use Core\Billing\Domain\Model\SubscriptionStatus;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Support\Access\AccessScope;

/**
 * ADR-0072 §7 — `GET /v1/subscriptions?customer_ref=&status=&cursor=`:
 * the contracts of one project, page after page.
 *
 * The scope is a **required** parameter (ADR-0069 §2).
 */
final readonly class ListSubscriptionsUseCase implements ListSubscriptions
{
    public function __construct(
        private SubscriptionDirectory $subscriptions,
        private CustomerRepository $customers,
        private SubscriptionViewBuilder $views,
    ) {}

    public function list(ListSubscriptionsCommand $command, AccessScope $scope): SubscriptionPage
    {
        if (! $scope->allowsProject($command->projectId)) {
            return new SubscriptionPage([]);
        }

        $customerId = null;

        if ($command->customerRef !== null) {
            $customer = $this->customers->findByExternalRef($command->projectId, $command->customerRef);

            if ($customer === null) {
                // The customer is unknown in this project: there is no
                // contract to show, and nothing to tell apart (G15).
                return new SubscriptionPage([]);
            }

            $customerId = $customer->id;
        }

        // An unrecognised status is ignored, never guessed (same
        // convention as the other listings of the Core).
        $status = $command->status !== null && SubscriptionStatus::tryFrom($command->status) !== null
            ? $command->status
            : null;

        $page = $this->subscriptions->listByProject(
            $command->projectId,
            $customerId,
            $status,
            $command->cursor,
            $command->limit,
        );

        $items = array_map(
            fn (Subscription $subscription): SubscriptionView => $this->views->build($subscription),
            $page['items'],
        );

        return new SubscriptionPage($items, $page['nextCursor']);
    }
}
