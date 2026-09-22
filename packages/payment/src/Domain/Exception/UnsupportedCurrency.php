<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Exception;

use RuntimeException;

/**
 * The caller-supplied currency isn't in the Core's allow-list
 * (`ledger`'s `currencies` table — the exponent registry that makes
 * `Core\Support\Money` render amounts correctly per currency).
 */
final class UnsupportedCurrency extends RuntimeException
{
    public static function withCode(string $currencyCode): self
    {
        return new self("Currency '{$currencyCode}' is not supported.");
    }
}
