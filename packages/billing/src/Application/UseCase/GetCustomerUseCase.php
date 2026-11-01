<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\CustomerViews;
use Core\Billing\Contracts\CustomerView;
use Core\Billing\Contracts\GetCustomer;
use Core\Billing\Contracts\GetCustomerCommand;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Support\Access\AccessScope;

/**
 * ADR-0070 §2 — `GET /v1/customers/{external_ref}`.
 *
 * The scope is a **required** parameter (ADR-0069 §2): a customer of
 * another project is simply **not found** — never told apart from one
 * that does not exist (ADR-0070 §Sécurité).
 */
final readonly class GetCustomerUseCase implements GetCustomer
{
    public function __construct(
        private CustomerRepository $customers,
    ) {}

    public function get(GetCustomerCommand $command, AccessScope $scope): ?CustomerView
    {
        if (! $scope->allowsProject($command->projectId)) {
            return null;
        }

        $customer = $this->customers->findByExternalRef($command->projectId, $command->externalRef);

        if ($customer === null || $customer->projectId !== $command->projectId) {
            return null;
        }

        return CustomerViews::from($customer);
    }
}
