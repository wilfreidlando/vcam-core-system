<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0122 §2 — l'opérateur **refuse** la déclaration proposée.
 *
 * La déclaration refusée n'est plus en force : elle ne sera jamais rendue
 * (ADR-0122 §2).
 */
interface RejectTenantSchema
{
    public function reject(RejectTenantSchemaCommand $command): TenantSchemaView;
}
