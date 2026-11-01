<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

/** ADR-0082 §1 — a project holds a bounded number of active keys. */
final class ApiKeyLimitReached extends RuntimeException
{
    public static function for(int $limit): self
    {
        return new self(sprintf('Ce projet a déjà %d clés actives, le maximum. Révoquez une clé inutilisée avant d\'en créer une autre.', $limit));
    }
}
