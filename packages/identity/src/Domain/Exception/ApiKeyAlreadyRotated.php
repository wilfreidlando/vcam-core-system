<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

/** ADR-0082 §3 — a key is rotated once; rotate its replacement instead. */
final class ApiKeyAlreadyRotated extends RuntimeException
{
    public static function for(string $prefix): self
    {
        return new self(sprintf('La clé %s a déjà été remplacée. Faites tourner la nouvelle clé, ou révoquez celle-ci.', $prefix));
    }
}
