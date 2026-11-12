<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0122 §2, §6 — la déclaration de forme **en force** d'un projet.
 *
 * C'est le port que la console appelle (G16 : le `AccessScope` est un
 * paramètre requis de tout port de lecture que la console appelle) : la
 * console y lit la forme acceptée, et lui demande si une référence
 * respecte le motif déclaré — la validation se fait **à la saisie**, là
 * où l'erreur est rattrapable (ADR-0122 §6).
 */
interface GetTenantSchema
{
    /**
     * La déclaration acceptée du projet, ou `null` quand le projet n'en a
     * pas encore.
     */
    public function accepted(string $projectId, AccessScope $scope): ?TenantSchemaView;

    /**
     * The declaration proposed by the project and still waiting for the
     * operator's decision, or `null` when there is none.
     *
     * Only a **pending** declaration is rendered here: an accepted one is
     * read by `accepted()`, a superseded one is not read at all.
     */
    public function pending(string $projectId, AccessScope $scope): ?TenantSchemaView;

    /**
     * La référence respecte-t-elle le motif de la forme en force ?
     *
     * Sans déclaration acceptée, aucune référence n'est refusée : le Core
     * n'a rien à opposer.
     */
    public function acceptsReference(string $projectId, string $reference, AccessScope $scope): bool;
}
