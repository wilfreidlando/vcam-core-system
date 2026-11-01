<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0073 §2 — the collection attempt a project refers to is not one
 * of its own, or it is no longer pending.
 */
final class InvoicePaymentNotFound extends RuntimeException
{
    public static function becauseId(string $invoicePaymentId): self
    {
        return new self("No collection attempt carries the id \"{$invoicePaymentId}\" in this project.");
    }

    public static function becauseNotPending(string $invoicePaymentId, string $status): self
    {
        return new self("The collection attempt \"{$invoicePaymentId}\" is in state \"{$status}\" and cannot move any more.");
    }
}
