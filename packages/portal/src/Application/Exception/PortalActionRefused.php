<?php

declare(strict_types=1);

namespace Core\Portal\Application\Exception;

use RuntimeException;

/**
 * An action the customer asked for and the portal will not do — with a
 * sentence that is safe to show them.
 */
final class PortalActionRefused extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
