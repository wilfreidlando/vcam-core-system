<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0122 §2 — l'opérateur **accepte** la déclaration proposée.
 *
 * L'acceptation met la forme en force : elle remplace la précédente, et
 * une seule reste acceptée par projet (ADR-0122 §2).
 */
interface AcceptTenantSchema
{
    public function accept(AcceptTenantSchemaCommand $command): TenantSchemaView;
}
