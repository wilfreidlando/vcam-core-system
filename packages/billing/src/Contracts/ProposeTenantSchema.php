<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0122 §2 — l'application **propose** la forme de son locataire.
 *
 * La proposition ne devient jamais la forme en force : elle attend que
 * l'opérateur l'ait lue et acceptée (ADR-0122 §2).
 */
interface ProposeTenantSchema
{
    public function propose(ProposeTenantSchemaCommand $command): TenantSchemaView;
}
