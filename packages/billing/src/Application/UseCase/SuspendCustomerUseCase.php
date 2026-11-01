<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\CustomerViews;
use Core\Billing\Contracts\CustomerView;
use Core\Billing\Contracts\SuspendCustomer;
use Core\Billing\Contracts\SuspendCustomerCommand;
use Core\Billing\Domain\Exception\CustomerNotFound;
use Core\Billing\Domain\Exception\InvalidCustomerTransition;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Model\CustomerStatus;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0070 §2 — `POST /v1/customers/{external_ref}/suspend`.
 *
 * A suspended customer loses its rights (ADR-0072 §3) but its invoices
 * stay due. An erased customer is final (ADR-0083): it can be neither
 * suspended nor reactivated.
 */
final readonly class SuspendCustomerUseCase implements SuspendCustomer
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private CustomerRepository $customers,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function suspend(SuspendCustomerCommand $command): CustomerView
    {
        return $this->unitOfWork->transactional(function () use ($command): CustomerView {
            $customer = $this->mustFind($command->projectId, $command->externalRef);

            if ($customer->status === CustomerStatus::Suspended) {
                // The gesture is already applied: a replay changes nothing.
                return CustomerViews::from($customer);
            }

            if ($customer->isErased()) {
                throw InvalidCustomerTransition::becauseNotAllowed(
                    $customer->status->value,
                    CustomerStatus::Suspended->value,
                );
            }

            $now = $this->clock->now();

            $customer->suspend($now);

            // The row moved: the ETag follows it (ADR-0070 §2).
            $customer->version++;

            $this->customers->save($customer);

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $command->projectId,
                'customer.suspended',
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
