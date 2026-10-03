<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

/**
 * ADR-0060 — a project has exactly one key per environment. Issuing a
 * second one is refused; regenerating (replace) is the explicit path.
 */
final class ApiKeyAlreadyExistsForEnvironment extends RuntimeException
{
    public static function for(string $projectId, string $environment, string $prefix): self
    {
        return new self(sprintf(
            'Ce projet a déjà une clé %s (%s). Régénérez-la pour la remplacer, ou révoquez-la d\'abord.',
            $environment,
            $prefix,
        ));
    }
}
