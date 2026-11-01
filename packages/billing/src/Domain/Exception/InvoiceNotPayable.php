<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0094 §8 — the invoice cannot be collected right now.
 *
 * Either it is already settled, or it is not a document yet (a draft
 * carries no receivable), or it was cancelled or written off. The
 * message says which, because the API layer turns it into an RFC 7807
 * problem detail and an operator has to act on it.
 */
final class InvoiceNotPayable extends RuntimeException
{
    public static function becauseAlreadySettled(string $invoiceId): self
    {
        return new self("The invoice \"{$invoiceId}\" is already settled: nothing more is due on it.");
    }

    public static function becauseStatus(string $invoiceId, string $status): self
    {
        return new self("The invoice \"{$invoiceId}\" is in state \"{$status}\" and cannot be collected.");
    }

    public static function becauseAttemptAlreadyPending(string $invoiceId): self
    {
        return new self(
            "The invoice \"{$invoiceId}\" already has a collection in progress: wait for it to settle, or cancel it before starting another one."
        );
    }

    /**
     * ADR-0094 §10 — an amount that covers more than what is due is
     * never imputed: the invoice would be over-paid, and the base
     * refuses it.
     */
    public static function becauseAboveRemaining(string $invoiceId, int $amountMinor, int $remainingMinor): self
    {
        return new self(
            "The invoice \"{$invoiceId}\" only has {$remainingMinor} minor units left to receive: {$amountMinor} were offered."
        );
    }

    /**
     * ADR-0073 §3 — the money of an invoice is in the invoice's own
     * currency: a receipt in another currency is another money.
     */
    public static function becauseCurrencyMismatch(string $invoiceId, string $currency): self
    {
        return new self("The invoice \"{$invoiceId}\" is not denominated in {$currency}.");
    }

    /**
     * ADR-0073 §4 — the method of an offline receipt decides the
     * account it is booked on: an unknown one would book the money
     * where nobody reconciles it.
     */
    public static function becauseUnknownMethod(string $method): self
    {
        return new self("The offline receipt method \"{$method}\" is not one of the known ones.");
    }

    /**
     * ADR-0073 §4 — a receipt of nothing is not a receipt.
     */
    public static function becauseNothingReceived(): self
    {
        return new self('An offline receipt must carry a positive amount.');
    }

    /**
     * ADR-0073 §4 — the date the money arrived is part of the receipt.
     */
    public static function becauseInvalidDate(string $value): self
    {
        return new self("The date \"{$value}\" of the offline receipt is not a readable date.");
    }
}
