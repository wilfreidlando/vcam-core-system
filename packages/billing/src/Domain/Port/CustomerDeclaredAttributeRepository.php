<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Port;

use DateTimeImmutable;

/**
 * ADR-0122 §5 — les valeurs déclarées d'un client.
 *
 * Les valeurs sont conservées **en clair**, comme `display_name` : ce sont
 * des valeurs d'exploitation, ni cherchées, ni indexées, ni chiffrées
 * (ADR-0122 §5).
 */
interface CustomerDeclaredAttributeRepository
{
    /**
     * Les valeurs déclarées d'un client, par clé.
     *
     * @return array<string, string>
     */
    public function forCustomer(string $projectId, string $customerId): array;

    /**
     * Remplace **l'ensemble** des valeurs déclarées d'un client : les clés
     * qui ne sont plus données disparaissent, les autres sont écrites.
     *
     * @param array<string, string> $values
     */
    public function replace(string $projectId, string $customerId, array $values, DateTimeImmutable $at): void;
}
