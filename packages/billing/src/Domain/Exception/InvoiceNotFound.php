<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0073 §1 — the invoice a project asks for is not one of its own.
 *
 * The id is the key the project knows its invoice by; the message names
 * it so the API layer can state which lookup failed.
 */
final class InvoiceNotFound extends RuntimeException
{
    public static function becauseId(string $invoiceId): self
    {
        return new self("No invoice carries the id \"{$invoiceId}\" in this project.");
    }
}
