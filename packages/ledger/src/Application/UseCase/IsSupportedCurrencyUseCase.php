<?php

declare(strict_types=1);

namespace Core\Ledger\Application\UseCase;

use Core\Ledger\Contracts\IsSupportedCurrency;
use Core\Ledger\Domain\Port\CurrencyRepository;

final readonly class IsSupportedCurrencyUseCase implements IsSupportedCurrency
{
    public function __construct(
        private CurrencyRepository $currencies,
    ) {}

    public function isSupported(string $currencyCode): bool
    {
        return $this->currencies->isSupported($currencyCode);
    }
}
