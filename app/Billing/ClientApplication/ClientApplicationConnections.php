<?php

declare(strict_types=1);

namespace App\Billing\ClientApplication;

/**
 * ADR-0133 §2 — où le branchement retenu est gardé.
 *
 * Un projet a **au plus** un branchement : le point d'entrée qui porte le
 * secret, et l'adresse corrigée, s'il y en a une.
 */
interface ClientApplicationConnections
{
    /**
     * Le branchement du projet, ou `null` quand rien n'a encore été retenu.
     */
    public function find(string $projectId): ?ClientApplicationConnection;

    /**
     * Le branchement retenu — il remplace le précédent.
     */
    public function save(ClientApplicationConnection $connection): void;
}
