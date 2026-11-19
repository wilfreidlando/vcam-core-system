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
    /**
     * ADR-0130 §5 — la proposition **et** ce qu'elle a fait : une
     * version créée, ou une forme déjà connue.
     */
    public function propose(ProposeTenantSchemaCommand $command): TenantSchemaProposal;
}
