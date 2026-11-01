<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0071 §5 — one currency per plan version, and the Core knows the
 * currencies the ledger supports: the check is delegated to
 * `Core\Ledger\Contracts\IsSupportedCurrency`, never to a list of its own.
 *
 * A version priced in a currency the ledger does not know could never be
 * invoiced or posted, so the catalogue refuses it up front.
 */
final class UnsupportedCurrency extends RuntimeException
{
    public static function becauseNotSupported(string $currency): self
    {
        return new self("The currency \"{$currency}\" is not supported (ADR-0071 §5).");
    }
}
