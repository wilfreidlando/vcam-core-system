<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\DeclaredAttributeValues;
use Core\Billing\Contracts\SetCustomerDeclaredAttributes;
use Core\Billing\Contracts\SetCustomerDeclaredAttributesCommand;
use Core\Billing\Domain\Exception\CustomerDeclaredAttributeRefused;
use Core\Billing\Domain\Port\CustomerDeclaredAttributeRepository;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\TenantSchemaRepository;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Core\Support\UnitOfWork;

/**
 * ADR-0122 §2, §5 — l'opérateur enregistre les valeurs déclarées d'un
 * client.
 *
 * Trois règles, et une seule source de vérité :
 *
 * - le client doit exister et appartenir au projet ;
 * - la **forme en force** doit exister : sans déclaration acceptée, le
 *   Core ne sait pas quelles clés existent (ADR-0122 §2) ;
 * - chaque clé est déclarée, chaque champ obligatoire est là, chaque
 *   valeur tient dans la longueur déclarée.
 *
 * Les valeurs sont conservées **en clair** : ce sont des valeurs
 * d'exploitation, ni secrètes, ni cherchées (ADR-0122 §5).
 */
final readonly class SetCustomerDeclaredAttributesUseCase implements SetCustomerDeclaredAttributes
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private CustomerRepository $customers,
        private CustomerDeclaredAttributeRepository $attributes,
        private TenantSchemaRepository $schemas,
        private Clock $clock,
    ) {}

    public function set(SetCustomerDeclaredAttributesCommand $command, AccessScope $scope): array
    {
        return $this->unitOfWork->transactional(function () use ($command, $scope): array {
            if (! $scope->allowsProject($command->projectId)) {
                throw CustomerDeclaredAttributeRefused::becauseProjectNotVisible($command->projectId);
            }

            $customer = $this->customers->findById($command->customerId);

            if ($customer === null || $customer->projectId !== $command->projectId) {
                throw CustomerDeclaredAttributeRefused::becauseUnknownCustomer();
            }

            $schema = $this->schemas->findAccepted($command->projectId);

            if ($schema === null) {
                throw CustomerDeclaredAttributeRefused::becauseNoAcceptedSchema($command->projectId);
            }

            $values = DeclaredAttributeValues::validated($schema, $command->values);

            $this->attributes->replace(
                $command->projectId,
                $command->customerId,
                $values,
                $this->clock->now(),
            );

            return $values;
        });
    }
}
