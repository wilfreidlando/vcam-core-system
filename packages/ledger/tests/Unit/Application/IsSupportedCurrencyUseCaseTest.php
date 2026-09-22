<?php

declare(strict_types=1);

use Core\Ledger\Application\UseCase\IsSupportedCurrencyUseCase;
use Core\Ledger\Tests\Unit\Fakes\InMemoryCurrencyRepository;

it('reports a seeded currency as supported', function (): void {
    $useCase = new IsSupportedCurrencyUseCase(new InMemoryCurrencyRepository(['XAF']));

    expect($useCase->isSupported('XAF'))->toBeTrue();
});

it('reports an unknown currency as unsupported', function (): void {
    $useCase = new IsSupportedCurrencyUseCase(new InMemoryCurrencyRepository(['XAF']));

    expect($useCase->isSupported('EUR'))->toBeFalse();
});
