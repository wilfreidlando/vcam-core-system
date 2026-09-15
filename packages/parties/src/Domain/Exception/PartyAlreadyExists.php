<?php

declare(strict_types=1);

namespace Core\Parties\Domain\Exception;

use RuntimeException;

/**
 * Raised when the `(project_id, external_ref)` unique constraint rejects an
 * insert — two concurrent lazy-creation calls raced, and this one lost.
 * Not an error from the caller's point of view: `FindOrCreateParty` catches
 * this and re-reads the party the winner created.
 */
final class PartyAlreadyExists extends RuntimeException
{
    public static function forExternalRef(string $projectId, string $externalRef): self
    {
        return new self("Party {$projectId}/{$externalRef} was created concurrently.");
    }
}
