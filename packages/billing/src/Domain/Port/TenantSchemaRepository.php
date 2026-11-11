<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Port;

use Core\Billing\Domain\Model\TenantSchema;

/**
 * ADR-0122 §2 — la persistance des déclarations de forme.
 *
 * Le port est du PHP pur (G3b) : il ne dit rien de la base. La garantie
 * « une seule déclaration acceptée par projet » est portée par l'index
 * partiel `project_tenant_schemas_project_accepted_unique`, et l'adaptateur
 * traduit sa violation.
 */
interface TenantSchemaRepository
{
    /**
     * La déclaration d'un projet, par son identifiant — jamais celle d'un
     * autre projet (ADR-0069 §2).
     */
    public function findById(string $projectId, string $id): ?TenantSchema;

    /**
     * La déclaration **en force** du projet : la seule acceptée
     * (ADR-0122 §2).
     */
    public function findAccepted(string $projectId): ?TenantSchema;

    /**
     * La déclaration **en attente de lecture** du projet, la plus récente.
     */
    public function findPending(string $projectId): ?TenantSchema;

    /**
     * La version la plus haute déjà proposée par le projet — `0` quand il
     * n'a jamais proposé de forme.
     */
    public function highestVersion(string $projectId): int;

    /**
     * Enregistre une déclaration, créée ou modifiée.
     */
    public function save(TenantSchema $schema): void;
}
