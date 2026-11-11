<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0122 §2 — the declaration is no longer waiting to be read.
 *
 * Only a **pending** declaration can be accepted or refused: an accepted
 * one is never accepted again (it is replaced), a refused one never comes
 * back to life.
 */
final class TenantSchemaNotPending extends RuntimeException
{
    public static function becauseStatus(string $status): self
    {
        return new self(
            "The declaration is no longer waiting to be read: its status is \"{$status}\" (ADR-0122 §2).",
        );
    }
}
