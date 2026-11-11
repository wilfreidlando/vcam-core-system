<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0122 §2, §5 — l'opérateur enregistre les valeurs déclarées d'un
 * client.
 *
 * Seule une déclaration **acceptée** est opposable : sans elle, le Core
 * ne sait pas quelles clés existent (ADR-0122 §2). Les valeurs sont
 * conservées en clair, comme `display_name` (ADR-0122 §5).
 */
interface SetCustomerDeclaredAttributes
{
    /**
     * Remplace l'ensemble des valeurs déclarées du client.
     *
     * @return array<string, string> les valeurs enregistrées
     */
    public function set(SetCustomerDeclaredAttributesCommand $command, AccessScope $scope): array;
}
