<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0122 §2 — the project already holds a declaration in force.
 *
 * A single declaration is accepted per project: the partial unique index
 * `project_tenant_schemas_project_accepted_unique` guarantees it, and
 * this exception translates its violation.
 */
final class TenantSchemaAlreadyAccepted extends RuntimeException
{
    public static function inProject(string $projectId): self
    {
        return new self(
            "Project {$projectId} already holds an accepted tenant schema (ADR-0122 §2).",
        );
    }
}
