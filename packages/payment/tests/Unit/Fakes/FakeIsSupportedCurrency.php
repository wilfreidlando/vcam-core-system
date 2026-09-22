<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Ledger\Contracts\IsSupportedCurrency;

final class FakeIsSupportedCurrency implements IsSupportedCurrency
{
    /** @param list<string> $supported */
    public function __construct(
        private array $supported = ['XAF', 'USD'],
    ) {}

    public function isSupported(string $currencyCode): bool
    {
        return in_array($currencyCode, $this->supported, true);
    }
}
