<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Exception;

use RuntimeException;

/**
 * The correction does not exist — or exists and belongs to another
 * tenant. ADR-0069 §2 / ADR-0081 S-04: a resource outside the caller's
 * scope is reported as "not found", never as "forbidden": a 403 would
 * confirm that it exists.
 */
final class LedgerAdjustmentNotFound extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Correction d\'exploitation %s introuvable.', $id));
    }

    public static function forProject(string $projectId): self
    {
        return new self(sprintf('Projet %s introuvable.', $projectId));
    }
}
