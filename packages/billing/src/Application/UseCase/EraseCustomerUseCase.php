<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\CustomerViews;
use Core\Billing\Contracts\CustomerView;
use Core\Billing\Contracts\EraseCustomer;
use Core\Billing\Contracts\EraseCustomerCommand;
use Core\Billing\Domain\Exception\CustomerNotFound;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Port\CustomerRepository;
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
