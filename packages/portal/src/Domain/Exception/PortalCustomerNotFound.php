<?php

declare(strict_types=1);

namespace Core\Portal\Domain\Exception;

use RuntimeException;

/**
 * ADR-0075 §1 — the customer a portal is opened for is not one of the project.
 */
final class PortalCustomerNotFound extends RuntimeException
{
    public static function forRef(string $ref): self
    {
        return new self("No customer carries the reference \"{$ref}\" in this project.");
    }
}
