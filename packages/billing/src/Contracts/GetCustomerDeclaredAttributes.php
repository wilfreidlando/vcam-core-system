<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0122 §4, §5 — les valeurs déclarées d'un client.
 *
 * Elles se **relisent** par `GET /v1/customers/{ref}`, avec la portée
 * `customers:read` — la même règle que l'e-mail et le téléphone
 * (ADR-0122 §4, §5). Elles ne voyagent jamais dans un événement.
 */
interface GetCustomerDeclaredAttributes
{
    /**
     * Les valeurs déclarées du client, par clé — un objet vide quand le
     * client n'en porte aucune.
     *
     * @return array<string, string>
     */
    public function forCustomer(string $projectId, string $customerId, AccessScope $scope): array;
}
