<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Exception;

use RuntimeException;

/**
 * ADR-0076 §3 (amended by ADR-0094 §9) — the collection's intention is
 * not part of the published vocabulary.
 *
 * Refused loudly, never silently ignored: an intention nobody honours
 * would book the money on the wrong account (a payment of an invoice
 * counted as plain revenue — the double counting ADR-0094 §9 closes).
 */
final class UnsupportedPaymentPurpose extends RuntimeException
{
    public static function withPurpose(string $purpose): self
    {
        return new self("Payment purpose '{$purpose}' is not supported.");
    }

    /**
     * The intention contradicts the rest of the command: a wallet credit
     * without a wallet, or a wallet credit on a payment that settles an
     * invoice.
     */
    public static function contradictory(string $purpose, string $reason): self
    {
        return new self("Payment purpose '{$purpose}' contradicts the payment: {$reason}.");
    }
}
