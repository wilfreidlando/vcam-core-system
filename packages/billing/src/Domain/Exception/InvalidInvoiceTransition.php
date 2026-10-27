<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0073 §1 — a transition the life cycle of an invoice does not
 * allow.
 *
 * The message states the rule that was broken, because the API layer
 * turns it into an RFC 7807 problem detail.
 */
final class InvalidInvoiceTransition extends RuntimeException
{
    public static function becauseNotAllowed(string $from, string $to): self
    {
        return new self("An invoice in state \"{$from}\" cannot move to \"{$to}\".");
    }

    /**
     * ADR-0073 §3 — a void is the counter-passation of the issuance; it
     * is only honest when nothing has been paid on the invoice.
     */
    public static function becauseSomethingWasPaid(int $amountPaidMinor): self
    {
        return new self(
            "An invoice that has already been paid cannot be voided: {$amountPaidMinor} minor units were received. Reverse the payment instead."
        );
    }

    /**
     * ADR-0073 §1 — a draft invoice has no number yet: the number is
     * attributed at the issuance, under the lock of the sequence.
     */
    public static function becauseAlreadyIssued(): self
    {
        return new self('An invoice that has already been issued cannot be issued again.');
    }

    /**
     * ADR-0073 §1 — an invoice without a line says nothing; it would be
     * a document with nothing on it.
     */
    public static function becauseNoLine(): self
    {
        return new self('An invoice carries at least one line.');
    }
}
