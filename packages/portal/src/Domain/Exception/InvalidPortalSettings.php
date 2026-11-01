<?php

declare(strict_types=1);

namespace Core\Portal\Domain\Exception;

use RuntimeException;

/**
 * ADR-0075 §4 — a setting of the portal that cannot be served safely.
 */
final class InvalidPortalSettings extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
