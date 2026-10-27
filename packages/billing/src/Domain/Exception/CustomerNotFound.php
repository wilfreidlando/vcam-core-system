<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0070 §1 — the customer a project asks for is not one of its own.
 *
 * The reference is the correlation key the project knows its customer by;
 * the message names it so the API layer can state which lookup failed
 * (ADR-0070 §2).
 */
final class CustomerNotFound extends RuntimeException
{
    public static function becauseExternalRef(string $externalRef): self
    {
        return new self("No customer carries the external reference \"{$externalRef}\" in this project.");
    }
}
