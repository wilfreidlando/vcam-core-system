<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Contracts\GetCustomerDeclaredAttributes;
use Core\Billing\Domain\Port\CustomerDeclaredAttributeRepository;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Support\Access\AccessScope;

/**
 * ADR-0122 §4, §5 — les valeurs déclarées d'un client.
 *
 * Elles se relisent, avec la portée `customers:read`, comme l'e-mail et
 * le téléphone. Un client d'un autre projet n'a pas de valeurs visibles :
 * le Core ne dit pas ce qu'il cache ailleurs (ADR-0069 §2).
 */
final readonly class GetCustomerDeclaredAttributesUseCase implements GetCustomerDeclaredAttributes
{
    public function __construct(
        private CustomerRepository $customers,
        private CustomerDeclaredAttributeRepository $attributes,
    ) {}

    public function forCustomer(string $projectId, string $customerId, AccessScope $scope): array
    {
        if (! $scope->allowsProject($projectId)) {
            return [];
        }

        $customer = $this->customers->findById($customerId);

        if ($customer === null || $customer->projectId !== $projectId) {
            return [];
        }

        return $this->attributes->forCustomer($projectId, $customerId);
    }
}
