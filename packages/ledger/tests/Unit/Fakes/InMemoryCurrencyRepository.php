<?php

declare(strict_types=1);

namespace Core\Ledger\Tests\Unit\Fakes;

use Core\Ledger\Domain\Port\CurrencyRepository;

final class InMemoryCurrencyRepository implements CurrencyRepository
{
    /** @param list<string> $supported */
    public function __construct(
        private array $supported = ['XAF', 'USD'],
    ) {}

    public function isSupported(string $code): bool
    {
        return in_array($code, $this->supported, true);
    }
}
