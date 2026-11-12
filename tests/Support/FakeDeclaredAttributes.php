<?php

declare(strict_types=1);

namespace Tests\Support;

use Core\Billing\Contracts\GetCustomerDeclaredAttributes;
use Core\Billing\Contracts\SetCustomerDeclaredAttributes;
use Core\Billing\Contracts\SetCustomerDeclaredAttributesCommand;
use Core\Support\Access\AccessScope;

/**
 * ADR-0122 §5 — les valeurs déclarées d'un client, gardées en mémoire.
 *
 * Le test qui l'utilise veut savoir **ce que la console a écrit**, pas
 * comment le module le garde : le doublon remplace donc les deux ports, et
 * rend les valeurs qu'il a reçues pour que le test les relise.
 */
final class FakeDeclaredAttributes implements GetCustomerDeclaredAttributes, SetCustomerDeclaredAttributes
{
    /**
     * The values, by customer.
     *
     * @var array<string, array<string, string>>
     */
    public array $stored = [];

    /**
     * The last set of values the console wrote.
     *
     * @var array<string, string>
     */
    public array $last = [];

    public function set(SetCustomerDeclaredAttributesCommand $command, AccessScope $scope): array
    {
        $this->stored[$command->customerId] = $command->values;
        $this->last = $command->values;

        return $command->values;
    }

    public function forCustomer(string $projectId, string $customerId, AccessScope $scope): array
    {
        return $this->stored[$customerId] ?? [];
    }
}
