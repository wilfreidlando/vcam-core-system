<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0073 §4 — the offline receipt a project refers to is not one of
 * its own.
 */
final class ManualReceiptNotFound extends RuntimeException
{
    public static function becauseId(string $receiptId): self
    {
        return new self("No offline receipt carries the id \"{$receiptId}\" in this project.");
    }
}
