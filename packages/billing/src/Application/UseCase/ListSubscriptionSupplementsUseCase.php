<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\SupplementViews;
use Core\Billing\Contracts\ListSubscriptionSupplements;
use Core\Billing\Contracts\ListSubscriptionSupplementsCommand;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\InvoiceRepository;
use Core\Billing\Domain\Port\SubscriptionRepository;
use Core\Billing\Domain\Port\SubscriptionSupplementRepository;
use Core\Support\Access\AccessScope;

/**
 * ADR-0117 §2 — the supplements of a contract: what was bought on top
 * of its plan, and what is still waiting for its payment.
 *
 * A contract of another project is answered like a missing one (G15,
 * ADR-0081 S-04): the listing returns nothing.
 */
final readonly class ListSubscriptionSupplementsUseCase implements ListSubscriptionSupplements
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private SubscriptionSupplementRepository $supplements,
        private CustomerRepository $customers,
        private InvoiceRepository $invoices,
    ) {}

    public function list(ListSubscriptionSupplementsCommand $command, AccessScope $scope): array
    {
        if (! $scope->allowsProject($command->projectId)) {
            return [];
        }

        $subscription = $this->subscriptions->findById($command->subscriptionId);

        if ($subscription === null || $subscription->projectId !== $command->projectId) {
            return [];
        }

        $customer = $this->customers->findById($subscription->customerId);
        $customerRef = $customer instanceof Customer ? $customer->externalRef : '';

        $views = [];

        foreach ($this->supplements->listBySubscription($subscription->id) as $supplement) {
            $views[] = SupplementViews::from($supplement, $customerRef, $this->invoiceNumberOf($supplement->invoiceId));
        }

        return $views;
    }

    private function invoiceNumberOf(?string $invoiceId): ?string
    {
        if ($invoiceId === null) {
            return null;
        }

        return $this->invoices->findById($invoiceId)?->number;
    }
}
