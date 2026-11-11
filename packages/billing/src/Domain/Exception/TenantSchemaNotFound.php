<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0122 §2 — the requested declaration does not belong to the project.
 *
 * The Core never says whether a declaration exists elsewhere: for the
 * calling project, it does not exist (ADR-0069 §2, the same rule as the
 * customers).
 */
final class TenantSchemaNotFound extends RuntimeException
{
    public static function forProject(string $projectId, string $schemaId): self
    {
        return new self(
            "No tenant schema carries this identifier in project {$projectId} (ADR-0122 §2).",
        );
    }
}
