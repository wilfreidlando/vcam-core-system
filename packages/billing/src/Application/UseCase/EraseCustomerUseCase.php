<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Service\SubscriptionViewBuilder;
use Core\Billing\Application\Support\CustomerViews;
use Core\Billing\Application\Support\SubscriptionEvents;
use Core\Billing\Contracts\CustomerView;
use Core\Billing\Contracts\EraseCustomer;
use Core\Billing\Contracts\EraseCustomerCommand;
use Core\Billing\Domain\Exception\CustomerNotFound;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\SubscriptionRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0070 §2 — `POST /v1/customers/{external_ref}/erase`.
 *
 * ADR-0083 — the erasure destroys the **identity**, not the history: the
 * row stays (the invoices and the contracts reference it), the personal
 * values go. The customer is final afterwards.
 */
final readonly class EraseCustomerUseCase implements EraseCustomer
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private CustomerRepository $customers,
        private SubscriptionRepository $subscriptions,
        private SubscriptionViewBuilder $subscriptionViews,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function erase(EraseCustomerCommand $command): CustomerView
    {
        return $this->unitOfWork->transactional(function () use ($command): CustomerView {
            $customer = $this->mustFind($command->projectId, $command->externalRef);

            if ($customer->isErased()) {
                // The identity is already gone: a replay changes nothing.
                return CustomerViews::from($customer);
            }

            $now = $this->clock->now();

            $customer->erase($now);

            // The row moved: the ETag follows it (ADR-0070 §2).
            $customer->version++;

            $this->customers->save($customer);

            // ADR-0083 §6.3 (F4.8) — an erased customer keeps no live contract:
            // nobody is left to renew it, and the scheduler must not go on
            // issuing invoices to a person who no longer exists. The invoices
            // already issued stay due (ADR-0083 §6 last paragraph).
            foreach ($this->subscriptions->listLiveByCustomer($customer->id) as $subscription) {
                $subscription->cancel($now, false);
                $this->subscriptions->save($subscription);

                $this->outbox->append(new DomainEvent(
                    $this->identifier->generate(),
                    $command->projectId,
                    'subscription.canceled',
                    SubscriptionEvents::payload(
                        $subscription,
                        $customer->externalRef,
                        $this->subscriptionViews->currentPeriod($subscription),
                    ),
                    $command->correlationId,
                    $now,
                ));
            }

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $command->projectId,
                'customer.erased',
                $this->payload($customer),
                $command->correlationId,
                $now,
            ));

            return CustomerViews::from($customer);
        });
    }

    private function mustFind(string $projectId, string $externalRef): Customer
    {
        return $this->customers->findByExternalRef($projectId, $externalRef)
            ?? throw CustomerNotFound::becauseExternalRef($externalRef);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Customer $customer): array
    {
        return [
            'customer_id' => $customer->id,
            'external_ref' => $customer->externalRef,
            'status' => $customer->status->value,
            'version' => $customer->version,
        ];
    }
}
