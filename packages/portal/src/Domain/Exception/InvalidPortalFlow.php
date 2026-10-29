<?php

declare(strict_types=1);

namespace Core\Portal\Domain\Exception;

use RuntimeException;

/**
 * ADR-0075 §1 — a `flow` the portal does not know, or one that lacks its target.
 */
final class InvalidPortalFlow extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
