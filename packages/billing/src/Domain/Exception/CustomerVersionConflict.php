<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0070 §2 — the optimistic lock of a customer: the caller sends the
 * version it last read (`If-Match`) and the write is refused when the
 * stored one has moved on (`412 version-conflict`).
 *
 * The customer is the one the project declares; two declarations racing on
 * the same reference must not overwrite each other silently.
 */
final class CustomerVersionConflict extends RuntimeException
{
    public static function forCustomer(string $externalRef, int $expectedVersion, int $currentVersion): self
    {
        return new self(sprintf(
            'The customer "%s" has moved on: version %d was expected but the stored version is %d (ADR-0070 §2).',
            $externalRef,
            $expectedVersion,
            $currentVersion,
        ));
    }
}
